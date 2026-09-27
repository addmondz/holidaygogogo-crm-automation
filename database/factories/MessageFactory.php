<?php

namespace Database\Factories;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'direction' => MessageDirection::Inbound,
            'type' => MessageType::Text,
            'body' => fake()->sentence(),
            'external_id' => 'wamid.'.fake()->unique()->regexify('[A-Za-z0-9]{24}'),
            'status' => MessageStatus::Received,
        ];
    }

    public function outbound(MessageStatus $status = MessageStatus::Sent): static
    {
        return $this->state(fn () => ['direction' => MessageDirection::Outbound, 'status' => $status]);
    }
}
