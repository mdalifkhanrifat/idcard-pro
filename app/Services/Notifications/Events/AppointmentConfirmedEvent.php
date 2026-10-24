<?php

namespace App\Services\Notifications\Events;

use App\Data\Notifications\NotificationRecipient;
use App\Models\Appointment;
use App\Models\Tenant;
use App\Services\Notifications\NotificationEvent;

/**
 * An appointment waiting for approval was confirmed by the organization, or a cancelled one restored (5.7a): the
 * person learns it stands.
 *
 * @implements NotificationEvent<Appointment>
 */
class AppointmentConfirmedEvent implements NotificationEvent
{
    use DescribesAppointments;

    public function key(): string
    {
        return 'appointment_confirmed';
    }

    public function placeholders(): array
    {
        return $this->appointmentPlaceholders();
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
        return $this->appointmentValues($subject);
    }

    public function sample(Tenant $tenant): Appointment
    {
        return $this->sampleAppointment($tenant);
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
