<?php

namespace App\Services\Appointments;

use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Repositories\Contracts\AppointmentRepositoryInterface;
use Carbon\CarbonImmutable;

/**
 * How full a place is, from its bookings that hold their place (all but cancelled ones): a booking of several
 * slots counts in each slot it covers, stepping from its start by the slot length.
 */
final class BookedAppointmentLoad implements AppointmentLoad
{
    public function __construct(private AppointmentRepositoryInterface $appointments) {}

    public function slots(AppointmentLocation $location, CarbonImmutable $from, CarbonImmutable $to, int $slotMinutes): array
    {
        $load = [];
        foreach ($this->appointments->heldTimes($location->id, $from, $to) as $appointment) {
            /** @var Appointment $appointment */
            $end = $appointment->ends_at?->getTimestamp() ?? 0;
            for ($start = (int) $appointment->starts_at?->getTimestamp(); $start < $end; $start += $slotMinutes * 60) {
                $load[$start] = ($load[$start] ?? 0) + 1;
            }
        }

        return $load;
    }

    public function days(AppointmentLocation $location, string $from, string $to): array
    {
        return $this->appointments->heldDays($location->id, $from, $to);
    }
}
