<?php

namespace App\Services\Webhooks;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Inbox\InboundRecorder;
use App\Services\Meta\MessengerClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Processes Facebook Page (Messenger) webhooks: messages, postbacks,
 * delivery/read receipts and echoes of replies sent from the Page inbox.
 *
 * @see https://developers.facebook.com/docs/messenger-platform/webhooks
 */
class MessengerWebhookHandler
{
    public function __construct(
        private readonly InboundRecorder $inbound,
        private readonly ReceiptRecorder $receipts,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            $channel = Channel::query()
                ->where('type', ChannelType::Messenger)
                ->where('external_id', (string) ($entry['id'] ?? ''))
                ->first();

            if (! $channel) {
                Log::warning('Messenger webhook for an unknown Facebook Page', ['page_id' => $entry['id'] ?? null]);

                continue;
            }

            foreach ($entry['messaging'] ?? [] as $event) {
                match (true) {
                    isset($event['message']) && ! empty($event['message']['is_echo']) => $this->recordEcho($channel, $event),
                    isset($event['message']) => $this->recordMessage($channel, $event),
                    isset($event['postback']) => $this->recordPostback($channel, $event),
                    isset($event['delivery']) => $this->applyWatermark($channel, $event, 'delivery', MessageStatus::Delivered),
                    isset($event['read']) => $this->applyWatermark($channel, $event, 'read', MessageStatus::Read),
                    default => null,
                };
            }
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function recordMessage(Channel $channel, array $event): void
    {
        $psid = (string) ($event['sender']['id'] ?? '');
        $message = $event['message'];

        if ($psid === '' || empty($message['mid'])) {
            return;
        }

        $customer = $this->customer($channel, $psid);
        $sentAt = isset($event['timestamp']) ? Carbon::createFromTimestampMs((int) $event['timestamp']) : null;
        $meta = array_filter(['reply_to' => $message['reply_to']['mid'] ?? null]) ?: null;

        foreach ($this->parts($message) as $i => $part) {
            $this->inbound->record($channel, $psid, $customer, [
                ...$part,
                'meta' => $meta,
                // One Messenger message can carry several photos; keep each as its own chat bubble.
                'external_id' => $i === 0 ? (string) $message['mid'] : $message['mid'].'#'.$i,
                'sent_at' => $sentAt,
            ]);
        }
    }

    /**
     * Button taps (e.g. "Get Started") arrive as postbacks.
     *
     * @param  array<string, mixed>  $event
     */
    private function recordPostback(Channel $channel, array $event): void
    {
        $psid = (string) ($event['sender']['id'] ?? '');

        if ($psid === '') {
            return;
        }

        $this->inbound->record($channel, $psid, $this->customer($channel, $psid), [
            'type' => MessageType::Interactive,
            'body' => $event['postback']['title'] ?? 'Tapped a button',
            'meta' => ['postback_payload' => $event['postback']['payload'] ?? null],
            'external_id' => (string) ($event['postback']['mid'] ?? 'postback.'.$psid.'.'.($event['timestamp'] ?? now()->getTimestampMs())),
            'sent_at' => isset($event['timestamp']) ? Carbon::createFromTimestampMs((int) $event['timestamp']) : null,
        ]);
    }

    /**
     * Replies typed in the Facebook Page inbox / Meta Business Suite. Our own
     * API sends also echo back; those carry our app ID and are skipped.
     *
     * @param  array<string, mixed>  $event
     */
    private function recordEcho(Channel $channel, array $event): void
    {
        $message = $event['message'];
        $appId = (string) ($message['app_id'] ?? '');

        if ($appId !== '' && $appId === (string) config('services.meta.app_id')) {
            return;
        }

        $psid = (string) ($event['recipient']['id'] ?? '');

        if ($psid === '' || empty($message['mid'])) {
            return;
        }

        foreach ($this->parts($message) as $i => $part) {
            $this->inbound->recordEcho($channel, $psid, [], [
                ...$part,
                'external_id' => $i === 0 ? (string) $message['mid'] : $message['mid'].'#'.$i,
                'sent_at' => isset($event['timestamp']) ? Carbon::createFromTimestampMs((int) $event['timestamp']) : null,
            ]);
        }
    }

    /**
     * Messenger reports "everything before this time was delivered/read".
     *
     * @param  array<string, mixed>  $event
     */
    private function applyWatermark(Channel $channel, array $event, string $key, MessageStatus $status): void
    {
        $conversation = Conversation::query()
            ->where('channel_id', $channel->id)
            ->where('external_id', (string) ($event['sender']['id'] ?? ''))
            ->first();

        $watermark = $event[$key]['watermark'] ?? null;

        if (! $conversation || ! $watermark) {
            return;
        }

        $conversation->messages()
            ->where('direction', MessageDirection::Outbound)
            ->whereIn('status', [MessageStatus::Sent, MessageStatus::Delivered])
            ->where('created_at', '<=', Carbon::createFromTimestampMs((int) $watermark))
            ->get()
            ->each(fn (Message $message) => $this->receipts->apply($message, $status));
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array{type: MessageType, body: ?string, media?: array<string, mixed>}>
     */
    private function parts(array $message): array
    {
        $text = $message['text'] ?? null;
        $parts = [];

        foreach ($message['attachments'] ?? [] as $attachment) {
            $url = $attachment['payload']['url'] ?? null;
            $type = match ($attachment['type'] ?? '') {
                'image' => isset($attachment['payload']['sticker_id']) ? MessageType::Sticker : MessageType::Image,
                'video' => MessageType::Video,
                'audio' => MessageType::Audio,
                'file' => MessageType::Document,
                default => null,
            };

            if ($type && $url) {
                $parts[] = ['type' => $type, 'body' => null, 'media' => ['remote_url' => $url]];
            } elseif (($attachment['type'] ?? '') === 'fallback' || ($attachment['type'] ?? '') === 'template') {
                $parts[] = ['type' => MessageType::Text, 'body' => trim(($attachment['title'] ?? 'Shared a link').' '.($attachment['url'] ?? ''))];
            }
        }

        if (filled($text)) {
            // Put the text first so it becomes the chat preview.
            array_unshift($parts, ['type' => MessageType::Text, 'body' => $text]);
        }

        return $parts ?: [['type' => MessageType::Unsupported, 'body' => 'This message type can\'t be shown here. Please check the Facebook Page inbox.']];
    }

    /**
     * Look up the customer's name the first time they message the Page.
     *
     * @return array{name?: ?string, avatar_url?: ?string}
     */
    private function customer(Channel $channel, string $psid): array
    {
        $known = Conversation::query()
            ->where('channel_id', $channel->id)
            ->where('external_id', $psid)
            ->exists();

        if ($known) {
            return [];
        }

        return rescue(fn () => (new MessengerClient($channel))->profile($psid), [], report: false);
    }
}
