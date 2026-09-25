<?php

namespace Database\Factories;

use App\Enums\ConversationStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'contact_id' => Contact::factory(),
            'external_id' => fn (array $attributes) => Contact::find($attributes['contact_id'])?->phone
                ?? fake()->unique()->numerify('601########'),
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'unread_count' => 0,
        ];
    }

    public function windowClosed(): static
    {
        return $this->state(fn () => ['last_inbound_at' => now()->subDays(2)]);
    }
}
