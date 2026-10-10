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
        return $this->byTenantUsers()
            ->with('causer:id,name,email')
            ->when($filter->logName, fn (Builder $q, string $v) => $q->where('log_name', $v))
            ->when($filter->event, fn (Builder $q, string $v) => $q->where('event', $v))
            ->when($filter->causerPublicId, fn (Builder $q, string $v) => $q->whereIn('causer_id', User::query()->where('public_id', $v)->select('id')))
            ->when($filter->dateFrom, fn (Builder $q, string $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filter->dateTo, fn (Builder $q, string $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('id')
            ->paginate(min($filter->perPage, 100))
            ->withQueryString();
    }

    public function logNames(): array
    {
        return $this->byTenantUsers()->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name')->all();
    }

    public function events(): array
    {
        return $this->byTenantUsers()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event')->all();
    }

    /**
     * User::query() is tenant-scoped, so only changes made by this tenant's users are returned.
     *
     * @return Builder<Activity>
     */
    private function byTenantUsers(): Builder
    {
        return Activity::query()
            ->where('causer_type', (new User)->getMorphClass())
            ->whereIn('causer_id', User::query()->select('id'));
    }
}
