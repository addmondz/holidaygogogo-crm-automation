<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Tells every agent's chat list to refresh. Carries only IDs: each browser
 * re-fetches its list, which applies the visibility rules.
 */
class ConversationChanged implements ShouldBroadcastNow
{
    public function __construct(
        public readonly Conversation $conversation,
        public readonly bool $inbound = false,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('inbox')];
    }

    public function broadcastAs(): string
    {
        return 'conversation.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->conversation->id,
            'assigned_user_id' => $this->conversation->assigned_user_id,
            'inbound' => $this->inbound,
        ];
    }
}
