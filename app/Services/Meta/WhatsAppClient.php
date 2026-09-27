<?php

namespace App\Services\Meta;

use App\Models\Channel;
use Illuminate\Support\Str;

/**
 * WhatsApp Business Platform (Cloud API).
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api
 */
class WhatsAppClient
{
    private GraphClient $graph;

    public function __construct(private readonly Channel $channel)
    {
        $this->graph = new GraphClient($channel->access_token);
    }

    /**
     * @return array{id: string, wa_id: ?string}
     */
    public function sendText(string $to, string $body): array
    {
        return $this->send($to, 'text', ['preview_url' => true, 'body' => $body]);
    }

    /**
     * @param  'image'|'video'|'audio'|'document'|'sticker'  $type
     * @return array{id: string, wa_id: ?string}
     */
    public function sendMedia(string $to, string $type, string $mediaId, ?string $caption = null, ?string $filename = null): array
    {
        $payload = ['id' => $mediaId];

        if ($caption && in_array($type, ['image', 'video', 'document'], true)) {
            $payload['caption'] = $caption;
        }

        if ($filename && $type === 'document') {
            $payload['filename'] = $filename;
        }

        return $this->send($to, $type, $payload);
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return array{id: string, wa_id: ?string}
     */
    public function sendTemplate(string $to, string $name, string $language, array $components): array
    {
        $template = ['name' => $name, 'language' => ['code' => $language]];

        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->send($to, 'template', $template);
    }

    /**
     * Upload a file so it can be sent. Returns the media ID.
     */
    public function uploadMedia(string $contents, string $filename, string $mime): string
    {
        if (GraphClient::isFake()) {
            return 'fake-media-'.Str::random(12);
        }

        $response = $this->graph->postFile(
            "{$this->channel->external_id}/media",
            ['messaging_product' => 'whatsapp', 'type' => $mime],
            'file',
            $contents,
            $filename,
            $mime,
        );

        return (string) $response['id'];
    }

    /**
     * Shows blue ticks to the customer.
     */
    public function markAsRead(string $messageId): void
    {
        if (GraphClient::isFake()) {
            return;
        }

        $this->graph->post("{$this->channel->external_id}/messages", [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ]);
    }

    /**
     * @return array{contents: string, mime: ?string}
     */
    public function downloadMedia(string $mediaId): array
    {
        $info = $this->graph->get($mediaId);
        $response = $this->graph->download((string) $info['url']);

        return [
            'contents' => $response->body(),
            'mime' => $info['mime_type'] ?? $response->header('Content-Type'),
        ];
    }

    /**
     * All message templates of the WhatsApp Business Account.
     *
     * @return list<array<string, mixed>>
     */
    public function templates(): array
    {
        $templates = [];
        $url = "{$this->channel->business_account_id}/message_templates";
        $query = ['fields' => 'id,name,language,status,category,components,parameter_format', 'limit' => 100];

        while ($url) {
            $page = $this->graph->get($url, $query);
            array_push($templates, ...($page['data'] ?? []));
            $url = $page['paging']['next'] ?? null;
            $query = [];
        }

        return $templates;
    }

    /**
     * Used by "Test connection" in Admin -> Channels.
     *
     * @return array<string, mixed>
     */
    public function phoneNumberInfo(): array
    {
        return $this->graph->get($this->channel->external_id, [
            'fields' => 'display_phone_number,verified_name,quality_rating',
        ]);
    }

    /**
     * Subscribe our app to the WhatsApp Business Account's webhooks.
     */
    public function subscribeApp(): void
    {
        $this->graph->post("{$this->channel->business_account_id}/subscribed_apps");
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, wa_id: ?string}
     */
    private function send(string $to, string $type, array $payload): array
    {
        if (GraphClient::isFake()) {
            return ['id' => 'wamid.FAKE'.Str::upper(Str::random(24)), 'wa_id' => $to];
        }

        $response = $this->graph->post("{$this->channel->external_id}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => $type,
            $type => $payload,
        ]);

        return [
            'id' => (string) ($response['messages'][0]['id'] ?? ''),
            'wa_id' => $response['contacts'][0]['wa_id'] ?? null,
        ];
    }
}
