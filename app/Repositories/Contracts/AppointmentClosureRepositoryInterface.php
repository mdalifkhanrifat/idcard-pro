<?php

namespace App\Repositories\Contracts;

use App\Data\Appointments\AppointmentClosureFilter;
use App\Models\AppointmentClosure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
}
