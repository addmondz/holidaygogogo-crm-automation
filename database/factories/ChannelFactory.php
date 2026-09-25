<?php

namespace Database\Factories;

use App\Enums\ChannelType;
use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => ChannelType::WhatsApp,
            'name' => 'HolidayGoGoGo WhatsApp',
            'external_id' => (string) fake()->unique()->numerify('1############'),
            'business_account_id' => (string) fake()->numerify('2############'),
            'display_phone' => '+60 12-345 6789',
            'access_token' => 'test-token',
            'is_active' => true,
        ];
    }

    public function messenger(): static
    {
        return $this->state(fn () => [
            'type' => ChannelType::Messenger,
            'name' => 'HolidayGoGoGo Facebook Page',
            'business_account_id' => null,
            'display_phone' => null,
        ]);
    }
}
