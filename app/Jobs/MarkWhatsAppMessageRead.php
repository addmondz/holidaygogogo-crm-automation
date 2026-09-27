<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Services\Meta\WhatsAppClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Shows blue ticks to the customer once an agent opens the chat.
 */
class MarkWhatsAppMessageRead implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public Channel $channel, public string $messageId)
    {
        $this->onQueue('messages');
    }

    public function handle(): void
    {
        rescue(fn () => (new WhatsAppClient($this->channel))->markAsRead($this->messageId), report: false);
    }
}
