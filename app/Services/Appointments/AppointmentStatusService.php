<?php

namespace App\Services\Appointments;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\User;
use App\Repositories\Contracts\AppointmentLocationRepositoryInterface;
use App\Repositories\Contracts\AppointmentRepositoryInterface;
use App\Services\Reports\ReportValueText;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Where an appointment stands (5.4d): cancelled by those who book while it is still ahead, and taken through
 * its steps by those who manage appointments — approved or turned down, came in (on its day), did not come
 * (once its time has passed), served — and back from a step taken by mistake; restored from cancelled only
 * while its time is still free (it takes its place again). The appointment's row is locked while it changes;
 * who changed it and when are kept, and the audit log has the step. Works while the module is off too, so
 * bookings left can be closed — except restoring, which books again.
 */
class AppointmentStatusService
{
    public function __construct(
        private AppointmentRepositoryInterface $appointments,
        private AppointmentLocationRepositoryInterface $locations,
        private AppointmentBookingService $booking,
        private TenantContext $tenant,
        private AppointmentNotifications $notifications,
    ) {}

    /**
     * Cancel one still ahead, by those who book — or by the person themself on the public link (`$by` null,
     * `$via` public).
     */
    public function cancel(Appointment $appointment, ?User $by, ?string $reason = null, ?CarbonImmutable $now = null, AppointmentSource $via = AppointmentSource::Staff): Appointment
    {
        $from = null;
        $cancelled = DB::transaction(function () use ($appointment, $by, $reason, $now, $via, &$from): Appointment {
            $appointment = $this->appointments->lockForChange($appointment);
            $problem = $this->cancelProblem($appointment);
            if ($problem !== null) {
                throw ValidationException::withMessages(['status' => $problem]);
            }
            $from = $appointment->status;

            return $this->apply($appointment, AppointmentStatus::Cancelled, $by, $reason, $now, via: $via);
        });
        $this->notifications->stepped($cancelled, $from);

        return $cancelled;
    }

    public function change(Appointment $appointment, AppointmentStatus $to, User $by, ?string $reason = null, ?CarbonImmutable $now = null): Appointment
    {
        $from = null;
        $changed = DB::transaction(function () use ($appointment, $to, $by, $reason, $now, &$from): Appointment {
            $appointment = $this->appointments->lockForChange($appointment);
            // Restoring takes a place: its lock next, before any other read (see lockForBooking())
            $place = $appointment->status === AppointmentStatus::Cancelled ? $this->locations->lockForBooking($appointment->location_id) : null;
            $now = $this->now($now);
            $from = $appointment->status;

            return $this->apply($appointment, $to, $by, $reason, $now, $this->check($appointment, $to, $now, $place));
        });
        $this->notifications->stepped($changed, $from);

        return $changed;
    }

    /**
     * Why an appointment cannot be cancelled now (it is no longer ahead), or null (5.5c — what the page offers).
     */
    public function cancelProblem(Appointment $appointment): ?string
    {
        return $appointment->status->isAhead() ? null : (string) __('appointments.changes.cannot_cancel', ['status' => $this->name($appointment->status)]);
    }

    /**
     * Why an appointment cannot take this step now, or null (5.5c — what the page offers): change()'s own checks,
     * without its locks — so whether a cancelled one still has room may change before the step is taken, and
     * change() checks again.
     */
    public function problem(Appointment $appointment, AppointmentStatus $to, ?CarbonImmutable $now = null): ?string
    {
        try {
            $this->check($appointment, $to, $this->now($now), $appointment->status === AppointmentStatus::Cancelled ? $appointment->loadMissing('location')->location : null);
        } catch (ValidationException $refused) {
            return (string) collect($refused->errors())->flatten()->first();
        } catch (BusinessRuleException $refused) {
            return $refused->getMessage();
        }

        return null;
    }

    /**
     * The rules of a step: allowed from where it stands, came in only on its day, did not come once its time has
     * begun (or its day is over), and a cancelled one restored takes its place again (`$place`, its own). Returns
     * the times a restored one takes.
     *
     * @return array<string, mixed>
     */
    private function check(Appointment $appointment, AppointmentStatus $to, CarbonImmutable $now, ?AppointmentLocation $place): array
    {
        $from = $appointment->status;
        if (! $from->canBecome($to)) {
            throw ValidationException::withMessages(['status' => __('appointments.changes.not_allowed', ['from' => $this->name($from), 'to' => $this->name($to)])]);
        }
        $today = $now->toDateString();
        $day = $appointment->date->toDateString();

        if ($to === AppointmentStatus::CheckedIn && $from === AppointmentStatus::Confirmed && $day !== $today) {
            throw ValidationException::withMessages(['status' => __('appointments.changes.not_today', ['date' => app(ReportValueText::class)->date($day)])]);
        }
        $started = $appointment->starts_at !== null ? $appointment->starts_at->lte($now) : $day < $today;
        if ($to === AppointmentStatus::NoShow && ! $started) {
            throw ValidationException::withMessages(['status' => __($appointment->starts_at !== null ? 'appointments.changes.not_started' : 'appointments.changes.day_not_over')]);
        }

        return $place instanceof AppointmentLocation ? $this->takePlaceAgain($appointment, $place, $now) : [];
    }

    /**
     * A cancelled appointment restored takes its place again: within the person's open bookings and while its
     * day and time are still free, by the rules of a new booking.
     *
     * @return array<string, mixed>
     */
    private function takePlaceAgain(Appointment $appointment, AppointmentLocation $place, CarbonImmutable $now): array
    {
        $limit = $this->tenant->get()->appointmentSettings()['open_per_service'];
        if ($this->appointments->countOpen($appointment->cardholder_id, $appointment->family_member_id, $appointment->service) >= $limit) {
            throw ValidationException::withMessages(['status' => __('appointments.booking.open_limit', ['count' => app(ReportValueText::class)->number($limit)])]);
        }
        try {
            [$startsAt, $endsAt] = $this->booking->claim($appointment, $place, $appointment->date->toDateString(), $appointment->starts_at?->setTimezone($this->timezone())->format('H:i'), $now);
        } catch (ValidationException $refused) {
            throw ValidationException::withMessages(['status' => __('appointments.changes.no_room', ['why' => collect($refused->errors())->flatten()->first()])]);
        }

        return ['starts_at' => $startsAt?->utc(), 'ends_at' => $endsAt?->utc()];
    }

    /**
     * @param  array<string, mixed>  $times
     */
    private function apply(Appointment $appointment, AppointmentStatus $to, ?User $by, ?string $reason, ?CarbonImmutable $now, array $times = [], AppointmentSource $via = AppointmentSource::Staff): Appointment
    {
        $this->appointments->update($appointment, [
            ...$times,
            'status' => $to,
            // A reason belongs to a cancelled appointment only
            'cancel_reason' => $to === AppointmentStatus::Cancelled ? ($reason ?: null) : null,
            // Stored in UTC: a time on the organization's clock would be written as its wall-clock reading
            'status_changed_at' => ($now ?? CarbonImmutable::now())->utc(),
            // Null: the person themself, on the public link — which `status_changed_via` says
            'status_changed_by_id' => $by?->id,
            'status_changed_via' => $via,
        ]);

        return $this->appointments->withDetails($appointment);
    }

    private function name(AppointmentStatus $status): string
    {
        return (string) __("appointments.statuses.{$status->value}");
    }

    private function now(?CarbonImmutable $now): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->setTimezone($this->timezone());
    }

    private function timezone(): string
    {
        return $this->tenant->get()->timezone ?: (string) config('app.timezone');
    }
}
