<?php

namespace Database\Factories;

use App\Models\CardTemplate;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardTemplate>
 */
class CardTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(2, true).' card',
            'code' => strtoupper(fake()->lexify('???')),
            'width' => CardTemplate::CR80['width'],
            'height' => CardTemplate::CR80['height'],
        ];
    }
}
