<?php

namespace App\Repositories\Contracts;

use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Appointments (5.4).
 *
 * @extends RepositoryInterface<Appointment>
 */
interface AppointmentRepositoryInterface extends RepositoryInterface
{
    /**
     * Open bookings (AppointmentStatus::open()) a person holds for a kind of visit: the cardholder's own, or —
     * given — one family member's.
     */
    public function countOpen(int $cardholderId, ?int $familyMemberId, string $service): int;

    /**
     * Booked times at a place that hold their place and overlap [from, to) (UTC): starts_at and ends_at.
     *
     * @return Collection<int, Appointment>
     */
    public function heldTimes(int $locationId, CarbonImmutable $from, CarbonImmutable $to): Collection;

    /**
     * People booked at a place on each day (Y-m-d, the organization's) between two days, both whole, holding
     * their place.
     *
     * @return array<string, int>
     */
    public function heldDays(int $locationId, string $from, string $to): array;
}
