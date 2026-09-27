<?php

namespace App\Services\Meta;

use App\Models\Channel;
use Illuminate\Support\Str;

/**
 * Facebook Page messaging (Messenger Platform Send API).
 *
 * @see https://developers.facebook.com/docs/messenger-platform/reference/send-api
 */
class MessengerClient
{
    private GraphClient $graph;

    public function __construct(private readonly Channel $channel)
    {
        $this->graph = new GraphClient($channel->access_token);
    }

    /**
     * @param  bool  $humanAgent  Send with the HUMAN_AGENT tag (allowed up to 7 days after the customer's last message).
     * @return array{id: string}
     */
    public function sendText(string $recipientId, string $text, bool $humanAgent = false): array
    {
        return $this->send($recipientId, ['text' => $text], $humanAgent);
    }

    /**
     * @param  'image'|'video'|'audio'|'file'  $type
     * @return array{id: string}
     */
    public function sendAttachment(string $recipientId, string $type, string $contents, string $filename, string $mime, bool $humanAgent = false): array
    {
        if (GraphClient::isFake()) {
            return ['id' => 'm_FAKE'.Str::random(24)];
        }

        $response = $this->graph->postFile(
            "{$this->channel->external_id}/messages",
            [
                'recipient' => json_encode(['id' => $recipientId]),
                'message' => json_encode(['attachment' => ['type' => $type, 'payload' => ['is_reusable' => false]]]),
                ...$this->messagingType($humanAgent),
            ],
            'filedata',
            $contents,
            $filename,
            $mime,
        );

        return ['id' => (string) ($response['message_id'] ?? '')];
    }

    /**
     * The customer's name and profile photo (needs the pages_messaging permission).
     *
     * @return array{name: ?string, avatar_url: ?string}
     */
    public function profile(string $recipientId): array
    {
        if (GraphClient::isFake()) {
            return ['name' => null, 'avatar_url' => null];
        }

        $profile = $this->graph->get($recipientId, ['fields' => 'first_name,last_name,profile_pic']);
        $name = trim(($profile['first_name'] ?? '').' '.($profile['last_name'] ?? ''));

        return ['name' => $name !== '' ? $name : null, 'avatar_url' => $profile['profile_pic'] ?? null];
    }

    /**
     * Used by "Test connection" in Admin -> Channels.
     *
     * @return array<string, mixed>
     */
    public function pageInfo(): array
    {
        return $this->graph->get($this->channel->external_id, ['fields' => 'name']);
    }

    /**
     * Subscribe our app to the Page's messaging webhooks.
     */
    public function subscribeApp(): void
    {
        $this->graph->post("{$this->channel->external_id}/subscribed_apps", [
            'subscribed_fields' => 'messages,messaging_postbacks,message_deliveries,message_reads,message_echoes',
        ]);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{id: string}
     */
    private function send(string $recipientId, array $message, bool $humanAgent): array
    {
        if (GraphClient::isFake()) {
            return ['id' => 'm_FAKE'.Str::random(24)];
        }

        $response = $this->graph->post("{$this->channel->external_id}/messages", [
            'recipient' => ['id' => $recipientId],
            'message' => $message,
            ...$this->messagingType($humanAgent),
        ]);

        return ['id' => (string) ($response['message_id'] ?? '')];
    }

    /**
     * @return array<string, string>
     */
    private function messagingType(bool $humanAgent): array
    {
        return $humanAgent
            ? ['messaging_type' => 'MESSAGE_TAG', 'tag' => 'HUMAN_AGENT']
            : ['messaging_type' => 'RESPONSE'];
    }
}
