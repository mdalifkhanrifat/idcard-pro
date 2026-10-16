<?php

namespace App\Services\Appointments;

use App\Models\AppointmentLocation;
use Carbon\CarbonImmutable;

/**
 * How full a place already is: the bookings that hold its time slots and days. Availability subtracts these
 * from the place's capacity. Until bookings exist (5.4) nothing is booked.
 */
interface AppointmentLoad
{
    /**
     * People booked in each time slot starting within [from, to), by the slot's start (a Unix timestamp); a
     * booking that takes several slots counts in each of them.
     *
     * @return array<int, int>
     */
    public function slots(AppointmentLocation $location, CarbonImmutable $from, CarbonImmutable $to, int $slotMinutes): array;

    /**
     * People booked on each day (Y-m-d, the organization's) from one day to another, both whole.
     *
     * @return array<string, int>
     */
    public function days(AppointmentLocation $location, string $from, string $to): array;
}
