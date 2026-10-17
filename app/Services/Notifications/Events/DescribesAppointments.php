<?php

namespace App\Services\Notifications\Events;

use App\Data\Notifications\NotificationRecipient;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Models\Tenant;
use App\Services\Appointments\AppointmentSettings;
use App\Services\Cards\CardNumberFormat;
use App\Services\Reports\ReportValueText;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * What every appointment event offers (5.4e): placeholders for the organization, the person (a family member
 * on their bookings) and the appointment — its number, kind of visit, place and address, day and time on the
 * organization's clock, status — as people read them in the current language. The message goes to the
 * cardholder, also for a family member's booking (as for cards: the cardholder is the one the organization is
 * in touch with).
 */
trait DescribesAppointments
{
    /** The sample's running number and time of day, e.g. A-26-00042 at 10:30 */
    private const SAMPLE_SEQUENCE = 42;

    private const SAMPLE_TIME = '10:30';

    /**
     * @return array<int, string>
     */
    protected function appointmentPlaceholders(): array
    {
        return ['organization.name', 'holder.name', 'holder.employee_no', 'appointment.number', 'appointment.service', 'appointment.place', 'appointment.address', 'appointment.date', 'appointment.time', 'appointment.when', 'appointment.status'];
    }

    protected function appointmentRecipient(Appointment $appointment): NotificationRecipient
    {
        return NotificationRecipient::forCardholder($appointment->cardholder);
    }

    /**
     * An appointment of an invented person for previews, held in memory only: two days ahead (the
     * organization's), at 10:30 when times are booked, of its first kind of visit on (a new card when on), its
     * number in the organization's format, an invented place; every relation set so nothing is read from the
     * database.
     */
    protected function sampleAppointment(Tenant $tenant, ?string $cancelReason = null): Appointment
    {
        $settings = $tenant->appointmentSettings();
        $timezone = $tenant->timezone ?: (string) config('app.timezone');
        $today = app(TenantContext::class)->today();
        $day = $today->addDays(2)->toDateString();
        $kinds = array_keys(array_filter($settings['services'], fn (array $kind) => $kind['enabled']));
        $service = in_array('new_card', $kinds, true) ? 'new_card' : ($kinds[0] ?? 'new_card');
        $startsAt = $settings['scheduling'] === 'slot' ? CarbonImmutable::parse($day.' '.self::SAMPLE_TIME, $timezone)->utc() : null;
        $holder = (new Cardholder)->forceFill([
            'full_name' => (string) __('notifications.samples.holder_name'),
            'employee_no' => (string) __('notifications.samples.employee_no'),
        ])->setRelation('tenant', $tenant);
        $place = (string) __('notifications.samples.place');
        $location = (new AppointmentLocation)->forceFill(['name' => $place, 'name_local' => $place, 'address' => (string) __('notifications.samples.address')]);

        return (new Appointment)->forceFill([
            'number' => (new CardNumberFormat($settings['number_format']))->render('', $today, self::SAMPLE_SEQUENCE),
            'service' => $service,
            'date' => $day,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt?->addMinutes($settings['slot_minutes'] * ($settings['services'][$service]['slots'] ?? 1)),
            'status' => AppointmentStatus::Confirmed,
            'cancel_reason' => $cancelReason,
        ])->setRelation('cardholder', $holder)->setRelation('familyMember', null)->setRelation('location', $location);
    }

    /**
     * @return array<string, string>
     */
    protected function appointmentValues(Appointment $appointment): array
    {
        $text = app(ReportValueText::class);
        $appointment->loadMissing(['cardholder.tenant', 'familyMember', 'location']);
        $tenant = $appointment->cardholder->tenant;
        $timezone = $tenant->timezone ?: (string) config('app.timezone');
        $date = $text->digits($appointment->date->format('d/m/Y'));
        $time = $appointment->starts_at === null ? '' : $text->digits($appointment->starts_at->setTimezone($timezone)->format('H:i'));
        $place = $appointment->location;

        return [
            'organization.name' => (string) $tenant->name,
            'holder.name' => (string) ($appointment->familyMember->name ?? $appointment->cardholder->full_name),
            'holder.employee_no' => $text->digits((string) $appointment->cardholder->employee_no),
            'appointment.number' => (string) $appointment->number,
            'appointment.service' => AppointmentSettings::serviceNames($tenant)[$appointment->service] ?? $appointment->service,
            // The place's name is in the organization's language; its other name in any other
            'appointment.place' => (string) (app()->getLocale() === $tenant->locale ? $place->name : ($place->name_local ?: $place->name)),
            'appointment.address' => (string) $place->address,
            'appointment.date' => $date,
            'appointment.time' => $time,
            // Right also for a day booked (served in turn): the day alone
            'appointment.when' => $time === '' ? $date : (string) __('notifications.appointment.when', ['date' => $date, 'time' => $time]),
            'appointment.status' => (string) __("appointments.statuses.{$appointment->status->value}"),
        ];
    }
}
