<?php

namespace App\Repositories\Contracts;

use App\Data\Audit\ActivityFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;
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
     * Every change of the criteria, read in batches (downloads).
     *
     * @return LazyCollection<int, Activity>
     */
    public function reportRows(ActivityFilter $filter): LazyCollection;

    /**
     * @return array{changes: int, people: int}
     */
    public function reportTotals(ActivityFilter $filter): array;

    /**
     * Kinds of record (log names) the current tenant's users have changed, for the filter.
     *
     * @return array<int, string>
     */
    public function logNames(): array;

    /**
     * Events the organization's users caused (created, updated, requirements_overridden, ...), for the filter.
     *
     * @return array<int, string>
     */
    public function events(): array;
}
