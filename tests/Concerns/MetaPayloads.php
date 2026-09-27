<?php

namespace Tests\Concerns;

use App\Models\Channel;

trait MetaPayloads
{
    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    protected function whatsappMessagePayload(Channel $channel, array $message, string $name = 'Kerry Tan'): array
    {
        return $this->whatsappPayload($channel, [
            'contacts' => [['wa_id' => $message['from'], 'profile' => ['name' => $name]]],
            'messages' => [[
                'id' => 'wamid.'.uniqid(),
                'timestamp' => (string) now()->timestamp,
                ...$message,
            ]],
        ]);
    }

    protected function whatsappTextPayload(Channel $channel, string $from, string $text, string $name = 'Kerry Tan', ?string $id = null): array
    {
        return $this->whatsappMessagePayload($channel, [
            'from' => $from,
            'id' => $id ?? 'wamid.'.uniqid(),
            'type' => 'text',
            'text' => ['body' => $text],
        ], $name);
    }

    protected function whatsappStatusPayload(Channel $channel, string $messageId, string $status, array $extra = []): array
    {
        return $this->whatsappPayload($channel, [
            'statuses' => [[
                'id' => $messageId,
                'status' => $status,
                'timestamp' => (string) now()->timestamp,
                'recipient_id' => '60123456789',
                ...$extra,
            ]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    protected function whatsappPayload(Channel $channel, array $value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $channel->business_account_id,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '60123456789', 'phone_number_id' => $channel->external_id],
                        ...$value,
                    ],
                ]],
            ]],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    protected function messengerPayload(Channel $channel, array $events): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => $channel->external_id,
                'time' => now()->getTimestampMs(),
                'messaging' => $events,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postSignedWebhook(string $uri, array $payload, string $secret = 'test-secret')
    {
        $body = json_encode($payload);

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], $body);
    }
}
