<?php

namespace Database\Factories;

use App\Enums\QualificationKind;
use App\Models\Cardholder;
use App\Models\Qualification;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Qualification>
 */
class QualificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'cardholder_id' => Cardholder::factory(),
            'kind' => QualificationKind::Education,
            'title' => fake()->randomElement(['SSC', 'HSC', 'BSc', 'MSc']),
            'institution' => fake()->company(),
            'year' => fake()->numberBetween(1990, 2024),
            'result' => '4.50',
        ];
    }
}
