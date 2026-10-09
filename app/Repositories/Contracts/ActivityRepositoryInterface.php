<?php

namespace App\Repositories\Contracts;

use App\Data\Audit\ActivityFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\Activitylog\Models\Activity;

interface ActivityRepositoryInterface
{
    /**
     * Changes caused by users of the current tenant.
     *
     * @return LengthAwarePaginator<int, Activity>
     */
    public function list(ActivityFilter $filter): LengthAwarePaginator;

    /**
     * Kinds of record (log names) the current tenant's users have changed, for the filter.
     *
     * @return array<int, string>
     */
    public function logNames(): array;
}
