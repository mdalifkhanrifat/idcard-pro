<?php

namespace App\Services\Notifications\Events;

use App\Data\Notifications\NotificationRecipient;
use App\Models\Appointment;
use App\Models\Tenant;
use App\Services\Notifications\NotificationEvent;

/**
 * An appointment was cancelled, or turned down while waiting for approval; its reason when one was given (sent from 5.7).
 *
 * @implements NotificationEvent<Appointment>
 */
class AppointmentCancelledEvent implements NotificationEvent
{
    use DescribesAppointments;

    public function key(): string
    {
        return 'appointment_cancelled';
    }

    public function placeholders(): array
    {
        return [...$this->appointmentPlaceholders(), 'appointment.cancel_reason'];
    }

    public function defaultChannels(): array
    {
        return ['mail', 'sms'];
    }

    /**
     * @param  Appointment  $subject
     */
    public function values(object $subject): array
    {
        $reason = trim((string) $subject->cancel_reason);

        // A whole sentence, empty when no reason was given
        return [...$this->appointmentValues($subject), 'appointment.cancel_reason' => $reason === '' ? '' : (string) __('notifications.appointment.reason', ['reason' => $reason])];
    }

    public function sample(Tenant $tenant): Appointment
    {
        return $this->sampleAppointment($tenant, (string) __('notifications.samples.cancel_reason'));
    }

    /**
     * @param  Appointment  $subject
     */
    public function recipient(object $subject): NotificationRecipient
    {
        return $this->appointmentRecipient($subject);
    }

    /**
     * @param  Appointment  $subject
     */
    public function links(object $subject): array
    {
        return ['appointment_id' => $subject->id];
    }
}
