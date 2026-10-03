<?php

namespace App\Repositories\Eloquent;

use App\Data\Audit\ActivityFilter;
use App\Models\User;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivityRepository implements ActivityRepositoryInterface
{
    public function list(ActivityFilter $filter): LengthAwarePaginator
    {
        // User::query() is tenant-scoped, so only changes made by this tenant's users are returned.
        $tenantUsers = User::query()->select('id');

        return Activity::query()
            ->with('causer:id,name,email')
            ->where('causer_type', (new User)->getMorphClass())
            ->whereIn('causer_id', $tenantUsers)
            ->when($filter->logName, fn (Builder $q, string $v) => $q->where('log_name', $v))
            ->when($filter->event, fn (Builder $q, string $v) => $q->where('event', $v))
            ->when($filter->causerPublicId, fn (Builder $q, string $v) => $q->whereIn('causer_id', User::query()->where('public_id', $v)->select('id')))
            ->when($filter->dateFrom, fn (Builder $q, string $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filter->dateTo, fn (Builder $q, string $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('id')
            ->paginate(min($filter->perPage, 100))
            ->withQueryString();
    }
}
