<?php

namespace App\Services\Appointments;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\Notifications\NotificationService;
use Throwable;

/**
 * Which message an appointment's change sends the person (5.7a) — one place for staff, the public link and any
 * other way, called once the change is saved: booked; moved to another place, day or time (not a note alone);
 * confirmed (approved while waiting, or a cancelled one restored); cancelled or turned down; and a reminder
 * before it (5.7c, AppointmentReminderService). Steps of the visit
 * itself (came in, done, did not come) and other corrections send nothing. Each goes as the organization's
 * notification settings say (off: nothing). A message that cannot be logged never undoes the change: it is
 * reported and the change stands.
 */
class AppointmentNotifications
{
    public function __construct(private NotificationService $notifications) {}

    public function booked(Appointment $appointment): void
    {
        $this->send('appointment_booked', $appointment);
    }

    public function moved(Appointment $appointment): void
    {
        $this->send('appointment_changed', $appointment);
    }

    public function reminded(Appointment $appointment): void
    {
        $this->send('appointment_reminder', $appointment);
    }

    /**
     * After a step from `$from` to the appointment's status now.
     */
    public function stepped(Appointment $appointment, AppointmentStatus $from): void
    {
        $event = match (true) {
            $appointment->status === AppointmentStatus::Cancelled && $from !== AppointmentStatus::Cancelled => 'appointment_cancelled',
            $appointment->status === AppointmentStatus::Confirmed && in_array($from, [AppointmentStatus::Pending, AppointmentStatus::Cancelled], true) => 'appointment_confirmed',
            default => null,
        };
        if ($event !== null) {
            $this->send($event, $appointment);
        }
    }

    private function send(string $event, Appointment $appointment): void
    {
        try {
            $this->notifications->notify($event, $appointment);
        } catch (Throwable $failure) {
            report($failure);
        }
    }
}
