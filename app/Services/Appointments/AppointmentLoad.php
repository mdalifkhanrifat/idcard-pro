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
     * The booked times at the place overlapping [from, to): each booking's start and end (Unix timestamps), one
     * pair per person. Availability counts every booking that overlaps a slot at all, so the count stays right
     * when the slot length, the place's hours or a kind's slots changed after booking. One appointment may be
     * left out (`except`, its id): the one being moved, which gives up its own place.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public function slots(AppointmentLocation $location, CarbonImmutable $from, CarbonImmutable $to, ?int $except = null): array;

    /**
     * People booked on each day (Y-m-d, the organization's) from one day to another, both whole; one appointment
     * may be left out (`except`).
     *
     * @return array<string, int>
     */
    public function days(AppointmentLocation $location, string $from, string $to, ?int $except = null): array;
}
