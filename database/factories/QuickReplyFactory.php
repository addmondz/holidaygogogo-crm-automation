<?php

namespace Database\Factories;

use App\Models\QuickReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuickReply>
 */
class QuickReplyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'is_shared' => true,
            'shortcut' => fake()->unique()->lexify('reply????'),
            'title' => fake()->sentence(3),
            'body' => 'Hi {first_name}, '.fake()->sentence(),
        ];
    }
}
