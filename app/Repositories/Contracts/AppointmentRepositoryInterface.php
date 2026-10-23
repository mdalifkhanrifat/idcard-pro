<?php

namespace App\Repositories\Contracts;

use App\Data\Appointments\AppointmentFilter;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Appointments (5.4).
 *
 * @extends RepositoryInterface<Appointment>
 */
interface AppointmentRepositoryInterface extends RepositoryInterface
{
    /**
     * A page of appointments matching the criteria, in the asked order, with what a list shows.
     *
     * @return LengthAwarePaginator<int, Appointment>
     */
    public function list(AppointmentFilter $filter): LengthAwarePaginator;

    /**
     * An appointment with everything its details show.
     */
    public function withDetails(Appointment $appointment): Appointment;

    /**
     * The appointment as it is now, its row locked until the transaction ends: two changes at once never both
     * apply to what each read before. Nothing else is loaded — take the place's lock next, before any plain
     * read (see AppointmentLocationRepositoryInterface::lockForBooking()).
     */
    public function lockForChange(Appointment $appointment): Appointment;

    /**
     * The kinds of visit bookings were made for (also those since removed from the settings).
     *
     * @return array<int, string>
     */
    public function usedServices(): array;

    /**
     * The list's criteria in the list's order — shared by the list and its report.
     *
     * @return Builder<Appointment>
     */
    public function ordered(AppointmentFilter $filter): Builder;

    /**
     * The list's criteria alone (no order, no relations) — for a report's rows and totals.
     *
     * @return Builder<Appointment>
     */
    public function criteria(AppointmentFilter $filter): Builder;

    /**
     * Every row of the list's criteria in its order, with what a report shows (for downloads).
     *
     * @return LazyCollection<int, Appointment>
     */
    public function reportRows(AppointmentFilter $filter): LazyCollection;

    /**
     * Counts over the list's criteria: every appointment and those of each status (`status_<key>`).
     *
     * @return array<string, int>
     */
    public function reportTotals(AppointmentFilter $filter): array;

    /**
     * Open bookings (AppointmentStatus::open()) a person holds for a kind of visit: the cardholder's own, or —
     * given — one family member's.
     */
    public function countOpen(int $cardholderId, ?int $familyMemberId, string $service): int;

    /**
     * Booked times at a place that hold their place and overlap [from, to) (UTC): starts_at and ends_at; one
     * appointment may be left out (`exceptId`).
     *
     * @return Collection<int, Appointment>
     */
    public function heldTimes(int $locationId, CarbonImmutable $from, CarbonImmutable $to, ?int $exceptId = null): Collection;

    /**
     * People booked at a place on each day (Y-m-d, the organization's) between two days, both whole, holding
     * their place; one appointment may be left out (`exceptId`).
     *
     * @return array<string, int>
     */
    public function heldDays(int $locationId, string $from, string $to, ?int $exceptId = null): array;

    /**
     * Whether any appointment (of any status — bookings are never deleted) is of this cardholder (theirs or a
     * family member's), this family member, or at this place: such records are kept.
     */
    public function existsFor(?int $cardholderId = null, ?int $familyMemberId = null, ?int $locationId = null): bool;
}
