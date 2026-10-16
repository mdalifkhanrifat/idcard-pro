<?php

namespace Database\Factories;

use App\Models\AppointmentClosure;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentClosure>
 */
class AppointmentClosureFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $from = now()->addDays(fake()->numberBetween(1, 60))->toDateString();

        return [
            'tenant_id' => Tenant::factory(),
            'location_id' => null,
            'date_from' => $from,
            'date_to' => $from,
            'yearly' => false,
            'reason' => fake()->randomElement(['Holiday', 'Office closed', 'Maintenance']),
        ];
    }

    public function yearly(): static
    {
        return $this->state(['yearly' => true]);
    }
}
