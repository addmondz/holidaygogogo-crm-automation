<?php

namespace App\Jobs;

use App\Services\Webhooks\MessengerWebhookHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMessengerWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload)
    {
        $this->onQueue('webhooks');
    }

    public function handle(MessengerWebhookHandler $handler): void
    {
        $handler->handle($this->payload);
    }
}
