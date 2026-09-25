<?php

namespace App\Jobs;

use App\Enums\BroadcastStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageStatus;
use App\Enums\RecipientStatus;
use App\Models\BroadcastRecipient;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Broadcasts\BroadcastLauncher;
use App\Services\Inbox\Outbox;
use App\Services\Inbox\Placeholders;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sends one blast message. Rate-limited per channel (CRM_BROADCAST_PER_MINUTE);
 * jobs over the limit wait and retry, so a big blast just takes longer.
 */
class SendBroadcastMessage implements ShouldQueue
{
    use Queueable;

    /** Real errors allowed before giving up (rate-limit waits don't count). */
    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $recipientId, public int $channelId)
    {
        $this->onQueue('broadcasts');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('broadcasts')];
    }

    public function handle(Outbox $outbox): void
    {
        $recipient = BroadcastRecipient::query()
            ->with(['broadcast.channel', 'broadcast.template', 'contact'])
            ->find($this->recipientId);

        if (! $recipient || $recipient->status !== RecipientStatus::Pending) {
            return;
        }

        $broadcast = $recipient->broadcast;
        $contact = $recipient->contact;

        if ($broadcast->status !== BroadcastStatus::Sending) {
            $this->finish($recipient, RecipientStatus::Skipped, 'Blast was cancelled.');

            return;
        }

        if ($contact->isOptedOut()) {
            $this->finish($recipient, RecipientStatus::Skipped, 'Contact opted out.');

            return;
        }

        $channel = $broadcast->channel;
        $conversation = $this->conversationFor($recipient);

        if (! $conversation) {
            $this->finish($recipient, RecipientStatus::Skipped, $channel->isWhatsApp() ? 'No phone number.' : 'No open Messenger chat in the last 24 hours.');

            return;
        }

        // A retry after a network error reuses the message created the first time.
        $message = $recipient->message_id ? Message::find($recipient->message_id) : null;

        if (! $message) {
            $meta = ['broadcast' => ['id' => $broadcast->id, 'name' => $broadcast->name]];

            try {
                $message = $channel->isWhatsApp()
                    ? $outbox->sendTemplate($conversation, null, $broadcast->template, $broadcast->template_params ?? [], $meta, queued: false)
                    : $outbox->sendBroadcastText($conversation, Placeholders::fill((string) $broadcast->body, $contact), $meta);
            } catch (ValidationException $e) {
                $this->finish($recipient, RecipientStatus::Skipped, collect($e->errors())->flatten()->first());

                return;
            }

            $recipient->update(['conversation_id' => $conversation->id, 'message_id' => $message->id]);
        }

        // Send right here, so the rate limit applies to the actual calls to Meta.
        SendMessage::dispatchSync($message);

        $message->refresh();
        $failed = $message->status === MessageStatus::Failed;

        $this->finish(
            $recipient,
            $failed ? RecipientStatus::Failed : RecipientStatus::Sent,
            $failed ? $message->error : null,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $recipient = BroadcastRecipient::find($this->recipientId);

        if ($recipient && $recipient->status === RecipientStatus::Pending) {
            $this->finish($recipient, RecipientStatus::Failed, 'Could not send: '.($exception?->getMessage() ?? 'unknown error'));
        }
    }

    private function conversationFor(BroadcastRecipient $recipient): ?Conversation
    {
        $channel = $recipient->broadcast->channel;
        $contact = $recipient->contact;

        $existing = Conversation::query()
            ->with(['channel', 'contact'])
            ->where('channel_id', $channel->id)
            ->where('contact_id', $contact->id)
            ->latest('last_message_at')
            ->first();

        if ($existing || $channel->isMessenger() || ! $contact->phone) {
            return $existing;
        }

        $conversation = Conversation::query()->firstOrCreate(
            ['channel_id' => $channel->id, 'external_id' => $contact->phone],
            ['contact_id' => $contact->id, 'status' => ConversationStatus::Open],
        );

        return $conversation->load(['channel', 'contact']);
    }

    private function finish(BroadcastRecipient $recipient, RecipientStatus $status, ?string $error = null): void
    {
        $recipient->update([
            'status' => $status,
            'error' => $error,
            'sent_at' => $status === RecipientStatus::Sent ? now() : $recipient->sent_at,
        ]);

        BroadcastLauncher::completeIfFinished($recipient->broadcast);
    }
}
