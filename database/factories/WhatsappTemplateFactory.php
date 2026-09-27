<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\WhatsappTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappTemplate>
 */
class WhatsappTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'meta_id' => fake()->numerify('###############'),
            'name' => fake()->unique()->lexify('promo_????'),
            'language' => 'en',
            'category' => 'MARKETING',
            'status' => 'APPROVED',
            'parameter_format' => 'POSITIONAL',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hi {{1}}, our {{2}} tour has new dates! Reply YES to learn more.'],
                ['type' => 'FOOTER', 'text' => 'Reply STOP to unsubscribe'],
            ],
        ];
    }
}
