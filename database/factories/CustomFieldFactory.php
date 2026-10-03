<?php

namespace Database\Factories;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomField>
 */
class CustomFieldFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'entity' => 'cardholder',
            'key' => fake()->unique()->lexify('field_????'),
            'label' => fake()->words(2, true),
            'type' => CustomFieldType::Text,
        ];
    }
}
