<?php

namespace App\Repositories\Contracts;

use App\Data\Appointments\AppointmentClosureFilter;
use App\Models\AppointmentClosure;
use App\Models\AppointmentLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Days appointments are not taken (5.3).
 *
 * @extends RepositoryInterface<AppointmentClosure>
 */
interface AppointmentClosureRepositoryInterface extends RepositoryInterface
{
    /**
     * The organization's closures by their first day, with their place.
     *
     * @return LengthAwarePaginator<int, AppointmentClosure>
     */
    public function list(AppointmentClosureFilter $filter): LengthAwarePaginator;

    /**
     * The closures that may touch a place between two days (Y-m-d, both whole): its own and every place's,
     * overlapping the days, or yearly ones (AppointmentClosure::covers() tells the days).
     *
     * @return Collection<int, AppointmentClosure>
     */
    public function affecting(AppointmentLocation $location, string $from, string $to): Collection;
}
