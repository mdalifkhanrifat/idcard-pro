<?php

namespace App\Services\Notifications\Events;

use App\Data\Notifications\MailExtras;
use App\Data\Notifications\NotificationRecipient;
use App\Models\Appointment;
use App\Models\NotificationMessage;
use App\Models\Tenant;
use App\Services\Notifications\AddsToMail;
use App\Services\Notifications\HasEventOptions;
use App\Services\Notifications\NotificationEvent;

/**
 * An appointment is near: the organization's reminder hours before it (sent from 5.7).
 *
 * @implements NotificationEvent<Appointment>
 */
class AppointmentReminderEvent implements AddsToMail, HasEventOptions, NotificationEvent
{
    use DescribesAppointments;

    public function key(): string
    {
        return 'appointment_reminder';
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

    /**
     * A calendar file and the number as a QR code (5.7b).
     */
    public function mailExtras(NotificationMessage $message, array $languages): MailExtras
    {
        return $this->appointmentMailExtras($message, $languages);
    }
}
