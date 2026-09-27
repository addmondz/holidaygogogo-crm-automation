<?php

namespace App\Services\Webhooks;

use App\Enums\ChannelType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Message;
use App\Services\Inbox\InboundRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Processes WhatsApp Cloud API webhooks: incoming messages, delivery/read
 * receipts, and (for coexistence numbers) messages sent from the phone app.
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/components
 */
class WhatsAppWebhookHandler
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
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                $channel = Channel::query()
                    ->where('type', ChannelType::WhatsApp)
                    ->where('external_id', $phoneNumberId)
                    ->first();

                if (! $channel) {
                    Log::warning('WhatsApp webhook for an unknown phone number ID', ['phone_number_id' => $phoneNumberId]);

                    continue;
                }

                $names = collect($value['contacts'] ?? [])
                    ->mapWithKeys(fn ($contact) => [$contact['wa_id'] ?? '' => $contact['profile']['name'] ?? null]);

                foreach ($value['messages'] ?? [] as $message) {
                    $this->recordMessage($channel, $message, $names->get($message['from'] ?? ''));
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->recordStatus($status);
                }

                foreach ($value['message_echoes'] ?? [] as $echo) {
                    $this->recordEcho($channel, $echo);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function recordMessage(Channel $channel, array $message, ?string $profileName): void
    {
        $from = (string) ($message['from'] ?? '');

        if ($from === '' || empty($message['id'])) {
            return;
        }

        $referral = $message['referral'] ?? null;

        $this->inbound->record(
            $channel,
            $from,
            [
                'name' => $profileName,
                'phone' => $from,
                'source' => $referral ? 'whatsapp_ad' : 'whatsapp',
            ],
            [
                ...$this->parse($message),
                'external_id' => (string) $message['id'],
                'sent_at' => isset($message['timestamp']) ? Carbon::createFromTimestamp((int) $message['timestamp']) : null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $echo
     */
    private function recordEcho(Channel $channel, array $echo): void
    {
        $to = (string) ($echo['to'] ?? '');

        if ($to === '' || empty($echo['id'])) {
            return;
        }

        $this->inbound->recordEcho($channel, $to, ['phone' => $to], [
            ...$this->parse($echo),
            'external_id' => (string) $echo['id'],
            'sent_at' => isset($echo['timestamp']) ? Carbon::createFromTimestamp((int) $echo['timestamp']) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function recordStatus(array $status): void
    {
        $message = Message::query()->where('external_id', $status['id'] ?? '')->first();
        $newStatus = MessageStatus::tryFrom((string) ($status['status'] ?? ''));

        if (! $message || ! $newStatus) {
            return;
        }

        $error = null;

        if ($newStatus === MessageStatus::Failed) {
            $first = $status['errors'][0] ?? [];
            $error = trim(($first['title'] ?? 'Message failed').'. '.($first['error_data']['details'] ?? $first['message'] ?? ''));
            $error .= isset($first['code']) ? " (Meta error {$first['code']})" : '';
        }

        $this->receipts->apply($message, $newStatus, $error);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{type: MessageType, body: ?string, media: ?array<string, mixed>, meta: ?array<string, mixed>}
     */
    private function parse(array $message): array
    {
        $type = (string) ($message['type'] ?? 'unsupported');
        $meta = array_filter([
            'reply_to' => $message['context']['id'] ?? null,
            'referral' => $message['referral'] ?? null,
        ]);

        $result = match ($type) {
            'text' => ['type' => MessageType::Text, 'body' => $message['text']['body'] ?? ''],
            'image', 'video', 'audio', 'document', 'sticker' => [
                'type' => MessageType::from($type),
                'body' => $message[$type]['caption'] ?? null,
                'media' => [
                    'remote_id' => $message[$type]['id'] ?? null,
                    'mime' => $message[$type]['mime_type'] ?? null,
                    'filename' => $message[$type]['filename'] ?? null,
                ],
            ],
            'location' => $this->location($message['location'] ?? []),
            'contacts' => [
                'type' => MessageType::Contacts,
                'body' => collect($message['contacts'] ?? [])
                    ->map(fn ($c) => trim(($c['name']['formatted_name'] ?? '').' '.collect($c['phones'] ?? [])->pluck('phone')->implode(', ')))
                    ->implode("\n"),
            ],
            'button' => [
                'type' => MessageType::Text,
                'body' => $message['button']['text'] ?? '',
                'meta' => ['button_payload' => $message['button']['payload'] ?? null],
            ],
            'interactive' => [
                'type' => MessageType::Interactive,
                'body' => $message['interactive']['button_reply']['title']
                    ?? $message['interactive']['list_reply']['title']
                    ?? 'Replied to a form',
            ],
            'reaction' => [
                'type' => MessageType::Reaction,
                'body' => $message['reaction']['emoji'] ?? '',
                'meta' => ['reacted_to' => $message['reaction']['message_id'] ?? null],
            ],
            'order' => ['type' => MessageType::Unsupported, 'body' => '🛒 Sent an order from your catalogue'],
            'system' => ['type' => MessageType::Unsupported, 'body' => $message['system']['body'] ?? 'System message'],
            default => [
                'type' => MessageType::Unsupported,
                'body' => 'This message type can\'t be shown here. Please check WhatsApp on your phone.',
            ],
        };

        return [
            'type' => $result['type'],
            'body' => $result['body'] ?? null,
            'media' => $result['media'] ?? null,
            'meta' => array_filter([...$meta, ...($result['meta'] ?? [])]) ?: null,
        ];
    }

    /**
     * @param  array<string, mixed>  $location
     * @return array{type: MessageType, body: string, meta: array<string, mixed>}
     */
    private function location(array $location): array
    {
        $lat = $location['latitude'] ?? null;
        $lng = $location['longitude'] ?? null;
        $label = trim(($location['name'] ?? '').' '.($location['address'] ?? ''));

        return [
            'type' => MessageType::Location,
            'body' => trim($label."\nhttps://maps.google.com/?q={$lat},{$lng}"),
            'meta' => ['latitude' => $lat, 'longitude' => $lng],
        ];
    }
}
