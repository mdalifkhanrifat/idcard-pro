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
}
