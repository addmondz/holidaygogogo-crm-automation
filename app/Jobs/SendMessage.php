<?php

namespace App\Jobs;

use App\Enums\ChannelType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\MessageSaved;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Inbox\MediaStore;
use App\Services\Meta\MessengerClient;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\WhatsAppClient;
use App\Support\Realtime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Delivers one outbound message to Meta. API errors (e.g. window closed,
 * invalid number) mark the message as failed; network errors are retried.
 */
class SendMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 15, 60, 180];

    public function __construct(public Message $message)
    {
        $this->onQueue('messages');
    }

    public function handle(): void
    {
        $message = $this->message->fresh(['conversation.channel', 'conversation.contact', 'user']);

        if (! $message || $message->status !== MessageStatus::Pending) {
            return; // Already sent (e.g. the job was retried after a timeout).
        }

        try {
            $result = $message->conversation->channel->type === ChannelType::WhatsApp
                ? $this->sendWhatsApp($message)
                : $this->sendMessenger($message);
        } catch (MetaApiException $e) {
            $this->markFailed($message, $e->friendlyMessage());

            return;
        }

        $message->forceFill(['external_id' => $result['id'] ?: null, 'status' => MessageStatus::Sent])->save();
        $this->syncWhatsAppId($message->conversation, $result['wa_id'] ?? null);

        Realtime::send(new MessageSaved($message));
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed($this->message->fresh(), 'Could not reach Meta. Please try again.');
    }

    /**
     * @return array{id: string, wa_id: ?string}
     */
    private function sendWhatsApp(Message $message): array
    {
        $client = new WhatsAppClient($message->conversation->channel);
        $to = $message->conversation->external_id;

        if ($message->type === MessageType::Template) {
            $template = $message->meta['template'];

            return $client->sendTemplate($to, $template['name'], $template['language'], $template['components'] ?? []);
        }

        if ($message->type->isMedia() && $message->media) {
            $media = $message->media;
            $mediaId = $client->uploadMedia(MediaStore::get($media['path']), $media['filename'] ?? 'file', $media['mime'] ?? 'application/octet-stream');

            return $client->sendMedia($to, $message->type->value, $mediaId, $message->body, $media['filename'] ?? null);
        }

        return $client->sendText($to, (string) $message->body);
    }

    /**
     * @return array{id: string}
     */
    private function sendMessenger(Message $message): array
    {
        $conversation = $message->conversation;
        $client = new MessengerClient($conversation->channel);
        $humanAgent = ! $conversation->isWindowOpen();

        if ($message->type->isMedia() && $message->media) {
            $media = $message->media;
            $type = match ($message->type) {
                MessageType::Image, MessageType::Sticker => 'image',
                MessageType::Video => 'video',
                MessageType::Audio => 'audio',
                default => 'file',
            };

            $result = $client->sendAttachment(
                $conversation->external_id,
                $type,
                MediaStore::get($media['path']),
                $media['filename'] ?? 'file',
                $media['mime'] ?? 'application/octet-stream',
                $humanAgent,
            );

            // Messenger attachments can't carry a caption, so send it separately.
            if (filled($message->body)) {
                $client->sendText($conversation->external_id, (string) $message->body, $humanAgent);
            }

            return $result;
        }

        return $client->sendText($conversation->external_id, (string) $message->body, $humanAgent);
    }

    /**
     * WhatsApp may report a different ID for the number we sent to (e.g. an
     * imported number without the right format). Keep the conversation keyed
     * by the real WhatsApp ID so the customer's replies land in the same chat.
     */
    private function syncWhatsAppId(Conversation $conversation, ?string $waId): void
    {
        if (! $waId || $waId === $conversation->external_id) {
            return;
        }

        $taken = Conversation::query()
            ->where('channel_id', $conversation->channel_id)
            ->where('external_id', $waId)
            ->exists();

        if (! $taken) {
            $conversation->update(['external_id' => $waId]);
        }
    }

    private function markFailed(?Message $message, string $error): void
    {
        if (! $message || $message->status !== MessageStatus::Pending) {
            return;
        }

        $message->forceFill(['status' => MessageStatus::Failed, 'error' => $error])->save();

        Realtime::send(new MessageSaved($message));
    }
}
