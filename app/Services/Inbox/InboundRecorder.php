<?php

namespace App\Services\Inbox;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\AgentNotified;
use App\Events\ConversationChanged;
use App\Events\MessageSaved;
use App\Jobs\DownloadInboundMedia;
use App\Models\BroadcastRecipient;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tag;
use App\Support\CrmSettings;
use App\Support\Realtime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Saves a message a customer sent (from any channel) and runs the inbox
 * automations: auto-assign, keyword tags, blast opt-out and reply tracking.
 */
class InboundRecorder
{
    public function __construct(private readonly AssignmentService $assignments) {}

    /**
     * @param  array{name?: ?string, phone?: ?string, avatar_url?: ?string, source?: ?string}  $customer
     * @param  array{external_id: string, type: MessageType, body?: ?string, media?: ?array<string, mixed>, meta?: ?array<string, mixed>, sent_at?: ?Carbon}  $data
     * @return Message|null Null when the message was already recorded (Meta retries webhooks).
     */
    public function record(Channel $channel, string $customerId, array $customer, array $data): ?Message
    {
        if (Message::query()->where('external_id', $data['external_id'])->exists()) {
            return null;
        }

        [$conversation, $isNew] = $this->conversationFor($channel, $customerId, $customer);
        $sentAt = $data['sent_at'] ?? now();

        try {
            $message = $conversation->messages()->create([
                'direction' => MessageDirection::Inbound,
                'type' => $data['type'],
                'body' => $data['body'] ?? null,
                'media' => $data['media'] ?? null,
                'meta' => $data['meta'] ?? null,
                'external_id' => $data['external_id'],
                'status' => MessageStatus::Received,
                'created_at' => $sentAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // The same webhook is being processed in parallel.
        }

        $conversation->increment('unread_count', 1, [
            'status' => ConversationStatus::Open->value,
            'last_inbound_at' => $sentAt,
            'last_message_at' => $sentAt,
            'last_message_preview' => $message->preview(),
        ]);

        $contact = $conversation->contact;
        $this->updateContact($contact, $customer);
        $this->applyKeywordTags($contact, (string) $message->body);
        $this->handleOptOut($contact, (string) $message->body);
        $this->trackBroadcastReply($contact);

        if (! empty($data['media']['remote_id']) || ! empty($data['media']['remote_url'])) {
            DownloadInboundMedia::dispatch($message);
        }

        Realtime::send(new MessageSaved($message));
        Realtime::send(new ConversationChanged($conversation, inbound: true));

        if ($isNew || ! $conversation->assigned_user_id) {
            $this->assignments->autoAssign($conversation);
        } elseif ($assignee = $conversation->assignee) {
            Realtime::send(new AgentNotified(
                $assignee,
                $contact->display_name,
                $message->preview(),
                $conversation->id,
            ));
        }

        return $message;
    }

    /**
     * A message the business sent from outside the CRM (the Facebook Page
     * inbox, or the WhatsApp Business app on a coexistence number), so the
     * chat history stays complete.
     *
     * @param  array{name?: ?string, phone?: ?string}  $customer
     * @param  array{external_id: string, type: MessageType, body?: ?string, media?: ?array<string, mixed>, meta?: ?array<string, mixed>, sent_at?: ?Carbon}  $data
     */
    public function recordEcho(Channel $channel, string $customerId, array $customer, array $data): ?Message
    {
        if (Message::query()->where('external_id', $data['external_id'])->exists()) {
            return null;
        }

        [$conversation] = $this->conversationFor($channel, $customerId, $customer);
        $sentAt = $data['sent_at'] ?? now();

        try {
            $message = $conversation->messages()->create([
                'direction' => MessageDirection::Outbound,
                'type' => $data['type'],
                'body' => $data['body'] ?? null,
                'media' => $data['media'] ?? null,
                'meta' => [...($data['meta'] ?? []), 'sent_outside_crm' => true],
                'external_id' => $data['external_id'],
                'status' => MessageStatus::Sent,
                'created_at' => $sentAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $conversation->update([
            'last_message_at' => $sentAt,
            'last_message_preview' => $message->preview(),
        ]);

        if (! empty($data['media']['remote_id']) || ! empty($data['media']['remote_url'])) {
            DownloadInboundMedia::dispatch($message);
        }

        Realtime::send(new MessageSaved($message));
        Realtime::send(new ConversationChanged($conversation));

        return $message;
    }

    /**
     * @param  array{name?: ?string, phone?: ?string, avatar_url?: ?string, source?: ?string}  $customer
     * @return array{0: Conversation, 1: bool}
     */
    private function conversationFor(Channel $channel, string $customerId, array $customer): array
    {
        $conversation = Conversation::query()
            ->with(['contact', 'assignee'])
            ->where('channel_id', $channel->id)
            ->where('external_id', $customerId)
            ->first();

        if ($conversation) {
            return [$conversation, false];
        }

        $phone = $customer['phone'] ?? null;
        $attributes = [
            'name' => $customer['name'] ?? null,
            'avatar_url' => $customer['avatar_url'] ?? null,
            'source' => $customer['source'] ?? $channel->type->value,
        ];

        // WhatsApp numbers identify the same person across channels and imports.
        $contact = $phone
            ? Contact::query()->createOrFirst(['phone' => $phone], $attributes)
            : Contact::query()->create($attributes);

        $conversation = Conversation::query()->createOrFirst(
            ['channel_id' => $channel->id, 'external_id' => $customerId],
            ['contact_id' => $contact->id, 'status' => ConversationStatus::Open],
        );

        $conversation->load(['contact', 'assignee']);

        return [$conversation, $conversation->wasRecentlyCreated];
    }

    /**
     * @param  array{name?: ?string, avatar_url?: ?string}  $customer
     */
    private function updateContact(Contact $contact, array $customer): void
    {
        if (! $contact->name && filled($customer['name'] ?? null)) {
            $contact->name = $customer['name'];
        }

        if (filled($customer['avatar_url'] ?? null)) {
            $contact->avatar_url = $customer['avatar_url'];
        }

        $contact->save();
    }

    private function applyKeywordTags(Contact $contact, string $text): void
    {
        if (trim($text) === '') {
            return;
        }

        $tagIds = Tag::query()
            ->whereNotNull('keywords')
            ->get()
            ->filter(fn (Tag $tag) => $tag->matches($text))
            ->modelKeys();

        if ($tagIds !== []) {
            $contact->tags()->syncWithoutDetaching($tagIds);
        }
    }

    private function handleOptOut(Contact $contact, string $text): void
    {
        $word = Str::upper(trim($text, " \t\n\r\0\x0B.!"));

        if ($word === '') {
            return;
        }

        if (in_array($word, CrmSettings::optOutKeywords(), true) && ! $contact->isOptedOut()) {
            $contact->update(['opted_out_at' => now()]);
        } elseif (in_array($word, CrmSettings::optInKeywords(), true) && $contact->isOptedOut()) {
            $contact->update(['opted_out_at' => null]);
        }
    }

    /**
     * Counts replies to blasts sent in the last 3 days.
     */
    private function trackBroadcastReply(Contact $contact): void
    {
        BroadcastRecipient::query()
            ->where('contact_id', $contact->id)
            ->whereNull('replied_at')
            ->where('sent_at', '>=', now()->subDays(3))
            ->update(['replied_at' => now()]);
    }
}
