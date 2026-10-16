<?php

namespace Database\Factories;

use App\Models\AppointmentLocation;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentLocation>
 */
class AppointmentLocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->randomElement(['Head office', 'Branch office', 'Counter', 'Gate']).' '.fake()->unique()->numberBetween(1, 99999),
            'slot_capacity' => 1,
            'day_capacity' => 50,
            'services' => null,
            'hours' => config('appointments.locations.hours'),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
