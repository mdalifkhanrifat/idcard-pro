<?php

namespace App\Services\Appointments;

use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Repositories\Contracts\AppointmentRepositoryInterface;
use Carbon\CarbonImmutable;

/**
 * How full a place is, from its bookings that hold their place (all but cancelled ones): their times, and the
 * people booked on each day.
 */
final class BookedAppointmentLoad implements AppointmentLoad
{
    public function __construct(private AppointmentRepositoryInterface $appointments) {}

    public function slots(AppointmentLocation $location, CarbonImmutable $from, CarbonImmutable $to, ?int $except = null): array
    {
        return $this->appointments->heldTimes($location->id, $from, $to, $except)
            ->map(fn (Appointment $appointment): array => [(int) $appointment->starts_at?->getTimestamp(), (int) $appointment->ends_at?->getTimestamp()])
            ->values()
            ->all();
    }

    public function days(AppointmentLocation $location, string $from, string $to, ?int $except = null): array
    {
        return $this->appointments->heldDays($location->id, $from, $to, $except);
    }
}
