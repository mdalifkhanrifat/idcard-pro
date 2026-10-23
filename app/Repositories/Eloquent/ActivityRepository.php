<?php

namespace App\Repositories\Eloquent;

use App\Data\Audit\ActivityFilter;
use App\Models\Cardholder;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Support\ListPage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\LazyCollection;
use Spatie\Activitylog\Models\Activity;

class ActivityRepository implements ActivityRepositoryInterface
{
    public function list(ActivityFilter $filter): LengthAwarePaginator
    {
        return $this->criteria($filter)
            ->with($this->causers())
            ->orderBy('id', $filter->descending ? 'desc' : 'asc')
            ->paginate(ListPage::clamp($filter->perPage))
            ->withQueryString();
    }

    public function reportRows(ActivityFilter $filter): LazyCollection
    {
        return $this->criteria($filter)->with($this->causers())->orderBy('id', $filter->descending ? 'desc' : 'asc')->lazy(500);
    }

    public function reportTotals(ActivityFilter $filter): array
    {
        // Users and cardholders number their ids apart, so each kind is counted on its own
        $totals = $this->criteria($filter)->toBase()
            ->selectRaw('count(*) as changes, count(distinct case when causer_type = ? then causer_id end) + count(distinct case when causer_type = ? then causer_id end) as people', [(new User)->getMorphClass(), (new Cardholder)->getMorphClass()])
            ->first();

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
     * Who acted, with only what the audit shows of them: a user, or a cardholder acting for themself (5.6 public
     * booking).
     *
     * @return array<string, \Closure(MorphTo<Model, Activity>): MorphTo<Model, Activity>>
     */
    private function causers(): array
    {
        return ['causer' => fn (MorphTo $causer): MorphTo => $causer->constrain([
            User::class => fn (Builder $query) => $query->select('id', 'name', 'email'),
            Cardholder::class => fn (Builder $query) => $query->select('id', 'full_name', 'employee_no'),
        ])];
    }

    /**
     * What this organization's audit shows: changes made by its users (User::query() is tenant-scoped) and by its
     * cardholders acting for themselves (the public booking link; Cardholder::query() is tenant-scoped too), and
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
                ->orWhere(fn (Builder $byCardholders) => $byCardholders->where('causer_type', (new Cardholder)->getMorphClass())->whereIn('causer_id', Cardholder::query()->select('id')))
                ->when($tenantId !== null, fn (Builder $query) => $query->orWhere(fn (Builder $bySystem) => $bySystem
                    ->whereNull('causer_id')
                    ->where('subject_type', (new Tenant)->getMorphClass())
                    ->where('subject_id', $tenantId)));
        });
    }
}
