<?php

namespace App\Repositories\Contracts;

use App\Models\Appointment;
use Carbon\CarbonImmutable;

interface AppointmentReminderRepositoryInterface
{
    /**
     * Take the right to send this reminder (the appointment, hours before, the moment it was due): true for the
     * one run that records it first, false when it was sent already — also by another run at the same time.
     */
    public function claim(Appointment $appointment, int $hours, CarbonImmutable $dueAt): bool;
}
