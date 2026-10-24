<?php

namespace App\Services\Notifications\Events;

use App\Data\Notifications\EventOption;
use App\Data\Notifications\MailExtras;
use App\Data\Notifications\NotificationRecipient;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Models\NotificationMessage;
use App\Models\Tenant;
use App\Services\Appointments\AppointmentSettings;
use App\Services\Cards\CardNumberFormat;
use App\Services\Notifications\NotificationSettings;
use App\Services\Reports\ReportValueText;
use App\Support\Calendar\CalendarEvent;
use App\Support\Calendar\IcsWriter;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Traits\Localizable;

/**
 * What every appointment event offers (5.4e): placeholders for the organization, the person (a family member
 * on their bookings) and the appointment — its number, kind of visit, place and address, day and time on the
 * organization's clock, status — as people read them in the current language. The message goes to the
 * cardholder, also for a family member's booking (as for cards: the cardholder is the one the organization is
 * in touch with).
 */
trait DescribesAppointments
{
    use Localizable;

    /** Its own setting: the public booking link in its messages, or not */
    public const LINK = 'link';

    public const LINK_INCLUDE = 'include';

    public const LINK_LEAVE_OUT = 'leave_out';

    /** The sample's running number and time of day, e.g. A-26-00042 at 10:30 */
    private const SAMPLE_SEQUENCE = 42;

    private const SAMPLE_TIME = '10:30';

    /**
     * @return array<int, string>
     */
    protected function appointmentPlaceholders(): array
    {
        return ['organization.name', 'holder.name', 'holder.employee_no', 'appointment.number', 'appointment.service', 'appointment.place', 'appointment.address', 'appointment.date', 'appointment.time', 'appointment.when', 'appointment.status', 'appointment.link'];
    }

    /**
     * Every appointment event's own setting (5.7e): whether its messages carry the public booking link (the
     * organization may leave it out without writing its own texts).
     *
     * @return array<int, EventOption>
     */
    public function eventOptions(): array
    {
        return [EventOption::choice(self::LINK, [self::LINK_INCLUDE, self::LINK_LEAVE_OUT], self::LINK_INCLUDE)];
    }

    /**
     * The public booking link as a sentence (5.7e), empty while the page is not open or the event leaves it out:
     * for a visit still to come what the person may do there — move it, cancel it, both, or see it as the
     * organization's settings allow; after a cancel, where to book again. The page never promises what is off.
     */
    protected function appointmentLink(Appointment $appointment, bool $bookAgain = false): string
    {
        $tenant = $appointment->cardholder->tenant;
        $url = AppointmentSettings::publicUrl($tenant);
        if ($url === null || NotificationSettings::option($tenant->notificationSettings(), $this->key(), self::LINK) !== self::LINK_INCLUDE) {
            return '';
        }
        $changes = $tenant->appointmentSettings()['changes'];
        $what = match (true) {
            $bookAgain => 'book',
            $changes['cancel'] && $changes['reschedule'] => 'move_or_cancel',
            $changes['cancel'] => 'cancel',
            $changes['reschedule'] => 'move',
            default => 'see',
        };

        return (string) __("notifications.appointment.link.{$what}", ['url' => $url]);
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
    protected function appointmentValues(Appointment $appointment, bool $bookAgain = false): array
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
            'appointment.link' => $this->appointmentLink($appointment, $bookAgain),
        ];
    }

    /**
     * What an appointment's e-mail carries (5.7b), as the organization's appointment settings say (`messages`): a
     * calendar file — the same UID for every message about it, so a move or a cancel updates the event a calendar
     * app holds (a newer sequence; cancelled when it is) — and, while the visit is still to come, its number as a
     * QR code read at the desk (5.5e). The file's words in the message's first language; the caption in each.
     *
     * @param  array<int, string>  $languages
     */
    protected function appointmentMailExtras(NotificationMessage $message, array $languages, bool $forTheVisit = true): MailExtras
    {
        $appointment = $message->appointment()->with(['cardholder.tenant', 'familyMember', 'location'])->first();
        if (! $appointment instanceof Appointment) {
            return new MailExtras;
        }
        $tenant = $appointment->cardholder->tenant;
        $wanted = $tenant->appointmentSettings()['messages'];
        $first = $languages[0] ?? (string) $tenant->locale;

        $files = $wanted['calendar'] ? [[
            'name' => "appointment-{$appointment->number}.ics",
            'mime' => IcsWriter::MIME,
            'data' => $this->withLocale($first, fn () => IcsWriter::write($this->calendarEvent($appointment))),
        ]] : [];
        $qr = $wanted['qr'] && $forTheVisit && $appointment->status->isOpen() ? (string) $appointment->number : null;
        $caption = $qr === null ? null : implode(' / ', array_unique(array_map(fn (string $language) => (string) __('notifications.appointment.qr', ['number' => $qr], $language), $languages ?: [$first])));

        return new MailExtras($qr, $caption, $files);
    }

    private function calendarEvent(Appointment $appointment): CalendarEvent
    {
        $values = $this->appointmentValues($appointment);
        $place = trim($values['appointment.place'].($values['appointment.address'] !== '' ? ', '.$values['appointment.address'] : ''));

        return new CalendarEvent(
            uid: $appointment->public_id.'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'idcard-pro'),
            // Grows with every change of the appointment (seconds since 2026 — well within a calendar's integer)
            sequence: max(0, (int) $appointment->updated_at?->getTimestamp() - 1_767_225_600),
            summary: (string) __('notifications.appointment.calendar_summary', ['service' => $values['appointment.service'], 'organization' => $values['organization.name']]),
            date: $appointment->date->toDateString(),
            start: $appointment->starts_at?->toImmutable(),
            end: $appointment->ends_at?->toImmutable(),
            location: $place,
            description: (string) __($appointment->status === AppointmentStatus::Cancelled ? 'notifications.appointment.calendar_cancelled' : 'notifications.appointment.calendar_description', ['number' => $values['appointment.number'], 'person' => $values['holder.name']]),
            status: match ($appointment->status) {
                AppointmentStatus::Cancelled => CalendarEvent::CANCELLED,
                AppointmentStatus::Pending => CalendarEvent::TENTATIVE,
                default => CalendarEvent::CONFIRMED,
            },
        );
    }
}
