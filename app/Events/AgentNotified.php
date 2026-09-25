<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A pop-up notification for one agent, e.g. "Chat assigned to you".
 */
class AgentNotified implements ShouldBroadcastNow
{
    public function __construct(
        public readonly User $agent,
        public readonly string $title,
        public readonly string $body,
        public readonly ?int $conversationId = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->agent->id)];
    }

    public function broadcastAs(): string
    {
        return 'agent.notified';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'conversation_id' => $this->conversationId,
        ];
    }
}
