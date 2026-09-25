<?php

namespace Database\Factories;

use App\Enums\ContactStatus;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '601'.fake()->unique()->numerify('########'),
            'email' => null,
            'status' => ContactStatus::New,
            'source' => 'whatsapp',
        ];
    }

    public function optedOut(): static
    {
        return $this->state(fn () => ['opted_out_at' => now()]);
    }
}
