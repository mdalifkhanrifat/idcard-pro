<?php

namespace App\Repositories\Contracts;

use App\Models\AppointmentLocation;
use Illuminate\Support\Collection;

/**
 * Places people come to for an appointment (5.3).
 *
 * @extends RepositoryInterface<AppointmentLocation>
 */
interface AppointmentLocationRepositoryInterface extends RepositoryInterface
{
    /**
     * The organization's places in their order (then by name), optionally only active ones or matching a search
     * (name in either language, or address).
     *
     * @return Collection<int, AppointmentLocation>
     */
    public function list(?string $search = null, ?bool $isActive = null): Collection;

    /**
     * How many places the organization has.
     */
    public function countAll(): int;

    /**
     * How many places the organization has, and how many of them are on.
     *
     * @return array{total: int, active: int}
     */
    public function counts(): array;

    /**
     * The place read again under a row lock, so bookings at one place are made one after another (two at once
     * never both take its last place). Call inside a database transaction, before any plain read of it: MySQL
     * reads the rest of a transaction as of its first plain read, so one made before waiting for the lock would
     * not see the booking that held it.
     */
    public function lockForBooking(int $locationId): AppointmentLocation;
}
