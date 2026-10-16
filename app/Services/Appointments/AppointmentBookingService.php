<?php

namespace App\Services\Appointments;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\BookingAudience;
use App\Exceptions\BusinessRuleException;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\User;
use App\Repositories\Contracts\AppointmentLocationRepositoryInterface;
use App\Repositories\Contracts\AppointmentRepositoryInterface;
use App\Repositories\Contracts\CardholderRepositoryInterface;
use App\Repositories\Contracts\FamilyMemberRepositoryInterface;
use App\Services\Reports\ReportValueText;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Books appointments by the organization's settings and the same rules as the free times (AvailabilityService):
 * appointments on, who books, the kind of visit (on, at the place, on the public link), the place (on), its week
 * and closures, notice and days ahead, and what is left — checked again under a lock on the place, so two
 * bookings at once never both take its last place. Then: family members allowed, the person's open bookings
 * for the kind within the limit. Confirmed at once, or pending where bookings are approved (unless the one
 * booking may approve). A short number of the organization's format; in the audit log.
 */
class AppointmentBookingService
{
    public function __construct(
        private AppointmentRepositoryInterface $appointments,
        private AppointmentLocationRepositoryInterface $locations,
        private CardholderRepositoryInterface $cardholders,
        private FamilyMemberRepositoryInterface $familyMembers,
        private AvailabilityService $availability,
        private AppointmentNumbers $numbers,
        private TenantContext $tenant,
    ) {}

    /**
     * @param  array{location: string, service: string, cardholder: string, family_member?: string|null, date: string, time?: string|null, note?: string|null}  $data  validated input
     */
    public function book(array $data, ?User $by, AppointmentSource $source = AppointmentSource::Staff, ?CarbonImmutable $now = null): Appointment
    {
        $tenant = $this->tenant->get();
        $settings = $tenant->appointmentSettings();
        $audience = $source === AppointmentSource::Public ? BookingAudience::Public : BookingAudience::Staff;
        /** @var AppointmentLocation $location */
        $location = $this->locations->findByPublicId($data['location']);
        /** @var Cardholder $cardholder */
        $cardholder = $this->cardholders->findByPublicId($data['cardholder']);
        $member = $this->member($data['family_member'] ?? null, $cardholder, $settings['family_members']);

        return DB::transaction(function () use ($data, $by, $source, $now, $tenant, $settings, $audience, $location, $cardholder, $member): Appointment {
            $location = $this->locations->lockForBooking($location);
            $day = $this->bookableDay($location, $data, $audience, $now);
            [$startsAt, $endsAt] = $settings['scheduling'] === 'slot' ? $this->slot($day, $data['time'] ?? null) : [null, null];

            $limit = $settings['open_per_service'];
            if ($this->appointments->countOpen($cardholder->id, $member?->id, $data['service']) >= $limit) {
                throw ValidationException::withMessages(['service' => __('appointments.booking.open_limit', ['count' => app(ReportValueText::class)->number($limit)])]);
            }

            $approve = $settings['confirmation'] === 'approval' && ! ($by?->can('appointments.manage') ?? false);

            /** @var Appointment $appointment */
            $appointment = $this->appointments->create([
                'number' => $this->numbers->next($tenant, $this->tenant->today()),
                'location_id' => $location->id,
                'service' => $data['service'],
                'cardholder_id' => $cardholder->id,
                'family_member_id' => $member?->id,
                'date' => $data['date'],
                'starts_at' => $startsAt?->utc(),
                'ends_at' => $endsAt?->utc(),
                'status' => $approve ? AppointmentStatus::Pending : AppointmentStatus::Confirmed,
                'source' => $source,
                'booked_by_id' => $by?->id,
                'note' => $data['note'] ?? null,
            ]);

            return $appointment->load(['location', 'cardholder', 'familyMember', 'bookedBy']);
        });
    }

    /**
     * The day asked for as the free times see it now: open, within notice and days ahead, with room.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function bookableDay(AppointmentLocation $location, array $data, BookingAudience $audience, ?CarbonImmutable $now): array
    {
        $free = $this->availability->for($location, (string) $data['service'], (string) $data['date'], (string) $data['date'], $audience, $now);
        if ($free->unavailable !== null) {
            $field = match ($free->unavailable) {
                'place_off', 'not_at_place' => 'location',
                'unknown_service', 'service_off', 'service_not_public' => 'service',
                default => null,
            };
            $message = __("appointments.availability.unavailable.{$free->unavailable}");
            if ($field === null) {
                throw new BusinessRuleException($message);
            }
            throw ValidationException::withMessages([$field => $message]);
        }
        $day = $free->days[0] ?? null;
        if ($day === null) {
            throw ValidationException::withMessages(['date' => __('appointments.booking.not_bookable_day')]);
        }
        if (! $day['open']) {
            $reason = $day['closure'] === null ? null : ($day['closure']['reason'] ?? $day['closure']['reason_local']);
            throw ValidationException::withMessages(['date' => $reason ? __('appointments.booking.closed_for', ['reason' => $reason]) : __('appointments.booking.closed')]);
        }
        if ($free->scheduling === 'day' && ($day['available'] ?? 0) < 1) {
            throw ValidationException::withMessages(['date' => __('appointments.booking.day_full')]);
        }

        return $day;
    }

    /**
     * The free time asked for ("HH:MM" on the organization's clock) on a day: its start and end.
     *
     * @param  array<string, mixed>  $day
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function slot(array $day, ?string $time): array
    {
        if ($time === null || $time === '') {
            throw ValidationException::withMessages(['time' => __('appointments.booking.time_needed')]);
        }
        foreach ($day['slots'] ?? [] as $slot) {
            if (substr($slot['start'], 11, 5) === $time && $slot['available'] > 0) {
                return [CarbonImmutable::parse($slot['start']), CarbonImmutable::parse($slot['end'])];
            }
        }

        throw ValidationException::withMessages(['time' => __('appointments.booking.time_taken')]);
    }

    private function member(?string $publicId, Cardholder $cardholder, bool $allowed): ?FamilyMember
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }
        if (! $allowed) {
            throw ValidationException::withMessages(['family_member' => __('appointments.booking.family_off')]);
        }
        $member = $this->familyMembers->findByPublicId($publicId);
        if (! $member instanceof FamilyMember || $member->cardholder_id !== $cardholder->id) {
            throw ValidationException::withMessages(['family_member' => __('appointments.booking.family_not_theirs')]);
        }

        return $member;
    }
}
