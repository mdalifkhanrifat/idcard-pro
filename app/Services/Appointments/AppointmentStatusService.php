<?php

namespace App\Services\Appointments;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
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
        private AppointmentBookingService $booking,
        private TenantContext $tenant,
    ) {}

    public function cancel(Appointment $appointment, User $by, ?string $reason = null, ?CarbonImmutable $now = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $by, $reason, $now): Appointment {
            $appointment = $this->appointments->lockForChange($appointment);
            if (! $appointment->status->isAhead()) {
                throw ValidationException::withMessages(['status' => __('appointments.changes.cannot_cancel', ['status' => $this->name($appointment->status)])]);
            }

            return $this->apply($appointment, AppointmentStatus::Cancelled, $by, $reason, $now);
        });
    }

    public function change(Appointment $appointment, AppointmentStatus $to, User $by, ?string $reason = null, ?CarbonImmutable $now = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $to, $by, $reason, $now): Appointment {
            $appointment = $this->appointments->lockForChange($appointment);
            $from = $appointment->status;
            if (! $from->canBecome($to)) {
                throw ValidationException::withMessages(['status' => __('appointments.changes.not_allowed', ['from' => $this->name($from), 'to' => $this->name($to)])]);
            }
            $now = ($now ?? CarbonImmutable::now())->setTimezone($this->timezone());
            $today = $now->toDateString();
            $day = $appointment->date->toDateString();

            if ($to === AppointmentStatus::CheckedIn && $from === AppointmentStatus::Confirmed && $day !== $today) {
                throw ValidationException::withMessages(['status' => __('appointments.changes.not_today', ['date' => app(ReportValueText::class)->date($day)])]);
            }
            $started = $appointment->starts_at !== null ? $appointment->starts_at->lte($now) : $day < $today;
            if ($to === AppointmentStatus::NoShow && ! $started) {
                throw ValidationException::withMessages(['status' => __($appointment->starts_at !== null ? 'appointments.changes.not_started' : 'appointments.changes.day_not_over')]);
            }
            $times = [];
            if ($from === AppointmentStatus::Cancelled) {
                $times = $this->takePlaceAgain($appointment, $now);
            }

            return $this->apply($appointment, $to, $by, $reason, $now, $times);
        });
    }

    /**
     * A cancelled appointment restored takes its place again: within the person's open bookings and while its
     * day and time are still free, by the rules of a new booking.
     *
     * @return array<string, mixed>
     */
    private function takePlaceAgain(Appointment $appointment, CarbonImmutable $now): array
    {
        $limit = $this->tenant->get()->appointmentSettings()['open_per_service'];
        if ($this->appointments->countOpen($appointment->cardholder_id, $appointment->family_member_id, $appointment->service) >= $limit) {
            throw ValidationException::withMessages(['status' => __('appointments.booking.open_limit', ['count' => app(ReportValueText::class)->number($limit)])]);
        }
        try {
            [$startsAt, $endsAt] = $this->booking->claim($appointment, $appointment->location, $appointment->date->toDateString(), $appointment->starts_at?->setTimezone($this->timezone())->format('H:i'), $now);
        } catch (ValidationException $refused) {
            throw ValidationException::withMessages(['status' => __('appointments.changes.no_room', ['why' => collect($refused->errors())->flatten()->first()])]);
        }

        return ['starts_at' => $startsAt?->utc(), 'ends_at' => $endsAt?->utc()];
    }

    /**
     * @param  array<string, mixed>  $times
     */
    private function apply(Appointment $appointment, AppointmentStatus $to, User $by, ?string $reason, ?CarbonImmutable $now, array $times = []): Appointment
    {
        $this->appointments->update($appointment, [
            ...$times,
            'status' => $to,
            // A reason belongs to a cancelled appointment only
            'cancel_reason' => $to === AppointmentStatus::Cancelled ? ($reason ?: null) : null,
            'status_changed_at' => $now ?? CarbonImmutable::now(),
            'status_changed_by_id' => $by->id,
        ]);

        return $this->appointments->withDetails($appointment);
    }

    private function name(AppointmentStatus $status): string
    {
        return (string) __("appointments.statuses.{$status->value}");
    }

    private function timezone(): string
    {
        return $this->tenant->get()->timezone ?: (string) config('app.timezone');
    }
}
