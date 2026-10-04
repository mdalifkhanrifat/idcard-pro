<?php

namespace Database\Factories;

use App\Models\CardTemplate;
use App\Models\CardTemplateElement;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardTemplateElement>
 */
class CardTemplateElementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'card_template_id' => CardTemplate::factory(),
            'side' => 'front',
            'type' => 'field',
            'field' => 'full_name',
            'x' => 30,
            'y' => 20,
            'width' => 40,
            'height' => 6,
            'style' => ['font_family' => 'Inter', 'font_size' => 9, 'color' => '#111111', 'align' => 'left'],
        ];
    }
}
