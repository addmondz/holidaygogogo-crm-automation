<?php

namespace App\Jobs;

use App\Events\MessageSaved;
use App\Models\Message;
use App\Services\Inbox\MediaStore;
use App\Services\Meta\GraphClient;
use App\Services\Meta\WhatsAppClient;
use App\Support\Realtime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Copies a customer's photo/file from Meta to our storage. Meta's links
 * expire, so this runs as soon as the message arrives.
 */
class DownloadInboundMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public Message $message)
    {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $message = $this->message->fresh('conversation.channel');
        $media = $message?->media ?? [];

        if (! $message || ! empty($media['path']) || GraphClient::isFake()) {
            return;
        }

        if (! empty($media['remote_id'])) {
            $file = (new WhatsAppClient($message->conversation->channel))->downloadMedia($media['remote_id']);
            $contents = $file['contents'];
            $mime = $media['mime'] ?? $file['mime'];
        } elseif (! empty($media['remote_url'])) {
            $response = Http::timeout(120)->get($media['remote_url'])->throw();
            $contents = $response->body();
            $mime = $response->header('Content-Type') ?: ($media['mime'] ?? null);
        } else {
            return;
        }

        $stored = MediaStore::put($contents, $mime, $media['filename'] ?? null);

        $message->update(['media' => [...$media, ...$stored, 'filename' => $media['filename'] ?? null]]);

        Realtime::send(new MessageSaved($message));
    }
}
