<?php

namespace App\Services\Appointments;

use App\Models\AppointmentLocation;
use Carbon\CarbonImmutable;

/**
 * Nothing booked yet: bookings come with 5.4, which binds its own load in place of this one.
 */
final class NoAppointmentLoad implements AppointmentLoad
{
    public function slots(AppointmentLocation $location, CarbonImmutable $from, CarbonImmutable $to, int $slotMinutes): array
    {
        return [];
    }

    public function days(AppointmentLocation $location, string $from, string $to): array
    {
        return [];
    }
}
