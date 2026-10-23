<?php

namespace App\Services\Appointments\PublicBooking;

use App\Data\Appointments\AppointmentFilter;
use App\Data\Appointments\Availability;
use App\Enums\AppointmentSource;
use App\Enums\BookingAudience;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Repositories\Contracts\AppointmentRepositoryInterface;
use App\Services\Appointments\AppointmentBookingService;
use App\Services\Appointments\AppointmentStatusService;
use App\Services\Appointments\AvailabilityService;
use App\Services\Reports\ReportValueText;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A signed-in person's own appointments on the public link (5.6): the free times as the public sees them, booking
 * for themself or a family member (source "public"), and — as far as the organization allows — cancelling and
 * moving their own until some hours before; by the very services staff use, with the public's rules (the hours'
 * notice, the kinds of visit offered on the link). Another person's appointment is as if it did not exist.
 */
class PublicAppointmentService
{
    public function __construct(
        private TenantContext $tenant,
        private AppointmentRepositoryInterface $appointments,
        private AppointmentBookingService $booking,
        private AppointmentStatusService $statuses,
        private AvailabilityService $availability,
    ) {}

    public function availability(AppointmentLocation $location, string $service, string $from, string $to): Availability
    {
        return $this->availability->for($location, $service, $from, $to, BookingAudience::Public);
    }

    /**
     * Theirs and their family members', from the organization's today on, by day and time.
     *
     * @return LengthAwarePaginator<int, Appointment>
     */
    public function mine(Cardholder $person): LengthAwarePaginator
    {
        return $this->appointments->list(AppointmentFilter::fromArray(['filter' => ['cardholder' => $person->public_id], 'per_page' => 50]));
    }

    /**
     * @param  array{location: string, service: string, family_member?: string|null, date: string, time?: string|null, note?: string|null}  $data
     */
    public function book(Cardholder $person, array $data): Appointment
    {
        return $this->booking->book([...$data, 'cardholder' => $person->public_id], null, AppointmentSource::Public);
    }

    public function cancel(Cardholder $person, string $id, ?string $reason): Appointment
    {
        $appointment = $this->own($person, $id);
        $this->refuse($this->changeProblem($appointment, 'cancel'));

        return $this->statuses->cancel($appointment, null, $reason);
    }

    /**
     * @param  array{location?: string, date: string, time?: string|null}  $data
     */
    public function move(Cardholder $person, string $id, array $data): Appointment
    {
        $appointment = $this->own($person, $id);
        $this->refuse($this->changeProblem($appointment, 'move'));

        return $this->booking->move($appointment, $data, audience: BookingAudience::Public);
    }

    /**
     * What the person may do with one of theirs now: cancel and move, each allowed or why not.
     *
     * @return array{cancel: array{allowed: bool, reason: string|null}, move: array{allowed: bool, reason: string|null}}
     */
    public function actions(Appointment $appointment, ?CarbonImmutable $now = null): array
    {
        $verdict = fn (?string $problem) => ['allowed' => $problem === null, 'reason' => $problem];

        return ['cancel' => $verdict($this->changeProblem($appointment, 'cancel', $now)), 'move' => $verdict($this->changeProblem($appointment, 'move', $now))];
    }

    /**
     * Why the person cannot cancel or move it themself now, or null: it is no longer ahead, the organization does
     * not offer it on the link, or it is too close (changes.until_hours before its time — a day booked, before the
     * day starts).
     *
     * @param  'cancel'|'move'  $change
     */
    public function changeProblem(Appointment $appointment, string $change, ?CarbonImmutable $now = null): ?string
    {
        $rules = $this->tenant->get()->appointmentSettings()['changes'];
        $problem = $change === 'cancel' ? $this->statuses->cancelProblem($appointment) : $this->booking->moveProblem($appointment, BookingAudience::Public);
        if ($problem !== null) {
            return $problem;
        }
        if (! $rules[$change === 'cancel' ? 'cancel' : 'reschedule']) {
            return (string) __("appointments.public.no_{$change}");
        }
        $starts = $appointment->starts_at !== null ? CarbonImmutable::parse($appointment->starts_at) : $this->tenant->dayStart($appointment->date->toDateString());
        $hours = (int) $rules['until_hours'];
        if (($now ?? CarbonImmutable::now())->addHours($hours)->greaterThan($starts)) {
            return trans_choice('appointments.public.too_late', $hours, ['hours' => app(ReportValueText::class)->number($hours)]);
        }

        return null;
    }

    /**
     * One of the person's own (theirs or a family member's) by its public id; any other is not found.
     */
    private function own(Cardholder $person, string $id): Appointment
    {
        $appointment = $this->appointments->findByPublicId($id);
        if (! $appointment instanceof Appointment || $appointment->cardholder_id !== $person->id) {
            throw new NotFoundHttpException(__('appointments.public.not_yours'));
        }

        return $appointment;
    }

    private function refuse(?string $problem): void
    {
        if ($problem !== null) {
            throw ValidationException::withMessages(['status' => $problem]);
        }
    }
}
