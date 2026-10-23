<?php

namespace App\Repositories\Eloquent;

use App\Data\Audit\LoginEventFilter;
use App\Enums\LoginChannel;
use App\Enums\LoginEventType;
use App\Models\Cardholder;
use App\Models\LoginEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Contracts\LoginEventRepositoryInterface;
use App\Support\ListPage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

class LoginEventRepository implements LoginEventRepositoryInterface
{
    public function __construct(private TenantContext $tenantContext) {}

    public function record(LoginEventType $event, ?string $ipAddress, ?string $userAgent, ?User $user = null, ?Tenant $tenant = null, ?string $identifier = null, LoginChannel $channel = LoginChannel::Staff, ?Cardholder $cardholder = null): LoginEvent
    {
        return LoginEvent::query()->create([
            'tenant_id' => $tenant->id ?? $user->tenant_id ?? $cardholder?->tenant_id,
            'channel' => $channel,
            'user_id' => $user?->id,
            'cardholder_id' => $cardholder?->id,
            'identifier' => $identifier,
            'event' => $event,
            'ip_address' => $ipAddress,
            'user_agent' => substr((string) $userAgent, 0, 512),
        ]);
    }

    public function list(LoginEventFilter $filter): LengthAwarePaginator
    {
        return $this->criteria($filter)
            ->with(['user:id,name,email', 'cardholder:id,full_name,employee_no'])
            ->orderBy('id', $filter->descending ? 'desc' : 'asc')
            ->paginate(ListPage::clamp($filter->perPage))
            ->withQueryString();
    }

    public function reportRows(LoginEventFilter $filter): LazyCollection
    {
        return $this->criteria($filter)->with(['user:id,name,email', 'cardholder:id,full_name,employee_no'])->orderBy('id', $filter->descending ? 'desc' : 'asc')->lazy(500);
    }

    public function reportTotals(LoginEventFilter $filter): array
    {
        $totals = $this->criteria($filter)->toBase()
            ->selectRaw('count(*) as events, coalesce(sum(case when event = ? then 1 else 0 end), 0) as succeeded, count(distinct ip_address) as addresses', [LoginEventType::Succeeded->value])
            ->first();

        return ['events' => (int) ($totals->events ?? 0), 'succeeded' => (int) ($totals->succeeded ?? 0), 'addresses' => (int) ($totals->addresses ?? 0)];
    }

    /**
     * The audit page's criteria, without order.
     *
     * @return Builder<LoginEvent>
     */
    private function criteria(LoginEventFilter $filter): Builder
    {
        return LoginEvent::query()
            ->when($this->tenantContext->has(), fn (Builder $q) => $q->where('tenant_id', $this->tenantContext->id()))
            ->when($filter->event, fn (Builder $q, string $v) => $q->where('event', $v))
            ->when($filter->channel, fn (Builder $q, string $v) => $q->where('channel', $v))
            ->when($filter->identifier, fn (Builder $q, string $v) => $q->where('identifier', 'like', "%{$v}%"))
            ->when($filter->ipAddress, fn (Builder $q, string $v) => $q->where('ip_address', $v))
            ->when($filter->dateFrom, fn (Builder $q, string $v) => $q->where('created_at', '>=', $this->tenantContext->dayStart($v)))
            ->when($filter->dateTo, fn (Builder $q, string $v) => $q->where('created_at', '<', $this->tenantContext->dayStart($v)->addDay()));
    }
}
