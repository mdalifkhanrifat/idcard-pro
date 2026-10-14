<?php

namespace App\Repositories\Eloquent;

use App\Data\Audit\ActivityFilter;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Support\ListPage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;
use Spatie\Activitylog\Models\Activity;

class ActivityRepository implements ActivityRepositoryInterface
{
    public function list(ActivityFilter $filter): LengthAwarePaginator
    {
        return $this->criteria($filter)
            ->with('causer:id,name,email')
            ->orderBy('id', $filter->descending ? 'desc' : 'asc')
            ->paginate(ListPage::clamp($filter->perPage))
            ->withQueryString();
    }

    public function reportRows(ActivityFilter $filter): LazyCollection
    {
        return $this->criteria($filter)->with('causer:id,name,email')->orderBy('id', $filter->descending ? 'desc' : 'asc')->lazy(500);
    }

    public function reportTotals(ActivityFilter $filter): array
    {
        $totals = $this->criteria($filter)->toBase()->selectRaw('count(*) as changes, count(distinct causer_id) as people')->first();

        return ['changes' => (int) ($totals->changes ?? 0), 'people' => (int) ($totals->people ?? 0)];
    }

    /**
     * The audit page's criteria, without order.
     *
     * @return Builder<Activity>
     */
    private function criteria(ActivityFilter $filter): Builder
    {
        return $this->forTenant()
            ->when($filter->logName, fn (Builder $q, string $v) => $q->where('log_name', $v))
            ->when($filter->event, fn (Builder $q, string $v) => $q->where('event', $v))
            ->when($filter->causerPublicId, fn (Builder $q, string $v) => $q->whereIn('causer_id', User::query()->where('public_id', $v)->select('id')))
            ->when($filter->dateFrom, fn (Builder $q, string $v) => $q->where('created_at', '>=', app(TenantContext::class)->dayStart($v)))
            ->when($filter->dateTo, fn (Builder $q, string $v) => $q->where('created_at', '<', app(TenantContext::class)->dayStart($v)->addDay()));
    }

    public function logNames(): array
    {
        return $this->forTenant()->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name')->all();
    }

    public function events(): array
    {
        return $this->forTenant()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event')->all();
    }

    /**
     * What this organization's audit shows: changes made by its users (User::query() is tenant-scoped), and
     * what the system did to the organization itself with no one acting (e.g. deleting old sent messages, a
     * setting changed from the server's console). Nothing of another organization.
     *
     * @return Builder<Activity>
     */
    private function forTenant(): Builder
    {
        $tenantId = app(TenantContext::class)->id();

        return Activity::query()->where(function (Builder $query) use ($tenantId): void {
            $query->where(fn (Builder $byUsers) => $byUsers->where('causer_type', (new User)->getMorphClass())->whereIn('causer_id', User::query()->select('id')))
                ->when($tenantId !== null, fn (Builder $query) => $query->orWhere(fn (Builder $bySystem) => $bySystem
                    ->whereNull('causer_id')
                    ->where('subject_type', (new Tenant)->getMorphClass())
                    ->where('subject_id', $tenantId)));
        });
    }
}
