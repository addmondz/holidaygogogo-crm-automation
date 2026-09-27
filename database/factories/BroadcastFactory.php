<?php

namespace Database\Factories;

use App\Enums\BroadcastStatus;
use App\Models\Broadcast;
use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Broadcast>
 */
class BroadcastFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Promo '.fake()->monthName(),
            'channel_id' => Channel::factory(),
            'status' => BroadcastStatus::Draft,
            'audience' => ['tag_ids' => [], 'exclude_tag_ids' => [], 'statuses' => []],
        ];
    }
}
