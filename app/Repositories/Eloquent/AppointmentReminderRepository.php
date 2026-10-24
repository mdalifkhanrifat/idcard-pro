<?php

namespace App\Repositories\Eloquent;

use App\Models\Appointment;
use App\Models\AppointmentReminder;
use App\Repositories\Contracts\AppointmentReminderRepositoryInterface;
use Carbon\CarbonImmutable;

class AppointmentReminderRepository implements AppointmentReminderRepositoryInterface
{
    public function claim(Appointment $appointment, int $hours, CarbonImmutable $dueAt): bool
    {
        // The unique key decides between two runs: only one insert takes
        return AppointmentReminder::query()->insertOrIgnore([
            'tenant_id' => $appointment->tenant_id,
            'appointment_id' => $appointment->id,
            'hours' => $hours,
            'due_at' => $dueAt->utc()->format('Y-m-d H:i:s'),
            'created_at' => now()->utc()->format('Y-m-d H:i:s'),
        ]) === 1;
    }
}
