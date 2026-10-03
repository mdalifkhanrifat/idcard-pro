<?php

namespace Database\Factories;

use App\Models\Signatory;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Signatory>
 */
class SignatoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'designation' => fake()->randomElement(['Director', 'Head of Security', 'HR Manager']),
            'is_active' => true,
        ];
    }
}
