<?php

namespace Database\Factories;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A confirmed booking of a whole day by staff; give a time with `at()`.
 *
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'number' => 'A-'.now()->format('y').'-'.fake()->unique()->numerify('#####'),
            'location_id' => AppointmentLocation::factory(),
            'service' => 'new_card',
            'cardholder_id' => Cardholder::factory(),
            'family_member_id' => null,
            'date' => now()->addDays(3)->toDateString(),
            'starts_at' => null,
            'ends_at' => null,
            'status' => AppointmentStatus::Confirmed,
            'source' => AppointmentSource::Staff,
        ];
    }

    /**
     * Booked at a time (UTC), for some minutes; its day is the given one.
     */
    public function at(string $day, string $startsAtUtc, int $minutes = 15): static
    {
        return $this->state(fn () => ['date' => $day, 'starts_at' => $startsAtUtc, 'ends_at' => now()->parse($startsAtUtc)->addMinutes($minutes)]);
    }

    public function status(AppointmentStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
