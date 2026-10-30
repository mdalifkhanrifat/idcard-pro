<?php

namespace App\Services\Appointments;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\Notifications\NotificationService;
use App\Services\Webhooks\WebhookNotifier;
use Throwable;

/**
 * Which message an appointment's change sends the person (5.7a) — one place for staff, the public link and any
 * other way, called once the change is saved: booked; moved to another place, day or time (not a note alone);
 * confirmed (approved while waiting, or a cancelled one restored); cancelled or turned down; and a reminder
 * before it (5.7c, AppointmentReminderService). Steps of the visit
 * itself (came in, done, did not come) and other corrections send nothing. Each goes as the organization's
 * notification settings say (off: nothing). A message that cannot be logged never undoes the change: it is
 * reported and the change stands. Webhooks hear of the same changes, and of every step: `appointment.status_changed`
 * for any of them, so a system can follow one event, and the named one (confirmed, cancelled) as well.
 */
class AppointmentNotifications
{
    public function __construct(
        private NotificationService $notifications,
        private WebhookNotifier $webhooks,
    ) {}

    public function booked(Appointment $appointment): void
    {
        $this->send('appointment_booked', $appointment);
        $this->webhooks->tell('appointment.booked', $appointment);
    }

    public function moved(Appointment $appointment): void
    {
        $this->send('appointment_changed', $appointment);
        $this->webhooks->tell('appointment.moved', $appointment);
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
        [$message, $webhookEvent] = match (true) {
            $appointment->status === AppointmentStatus::Cancelled && $from !== AppointmentStatus::Cancelled => ['appointment_cancelled', 'appointment.cancelled'],
            $appointment->status === AppointmentStatus::Confirmed && in_array($from, [AppointmentStatus::Pending, AppointmentStatus::Cancelled], true) => ['appointment_confirmed', 'appointment.confirmed'],
            default => [null, null],
        };
        if ($message !== null) {
            $this->send($message, $appointment);
            $this->webhooks->tell($webhookEvent, $appointment);
        }
        $this->webhooks->tell('appointment.status_changed', $appointment);
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
