<?php

namespace App\Repositories\Eloquent;

use App\Data\Audit\LoginEventFilter;
use App\Enums\LoginEventType;
use App\Models\LoginEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Contracts\LoginEventRepositoryInterface;
use App\Support\ListPage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class LoginEventRepository implements LoginEventRepositoryInterface
{
    public function __construct(private TenantContext $tenantContext) {}

    public function record(LoginEventType $event, ?string $ipAddress, ?string $userAgent, ?User $user = null, ?Tenant $tenant = null, ?string $identifier = null): LoginEvent
    {
        return LoginEvent::query()->create([
            'tenant_id' => $tenant->id ?? $user?->tenant_id,
            'user_id' => $user?->id,
            'identifier' => $identifier,
            'event' => $event,
            'ip_address' => $ipAddress,
            'user_agent' => substr((string) $userAgent, 0, 512),
        ]);
    }

    public function list(LoginEventFilter $filter): LengthAwarePaginator
    {
        return LoginEvent::query()
            ->with('user:id,name,email')
            ->when($this->tenantContext->has(), fn (Builder $q) => $q->where('tenant_id', $this->tenantContext->id()))
            ->when($filter->event, fn (Builder $q, string $v) => $q->where('event', $v))
            ->when($filter->identifier, fn (Builder $q, string $v) => $q->where('identifier', 'like', "%{$v}%"))
            ->when($filter->ipAddress, fn (Builder $q, string $v) => $q->where('ip_address', $v))
            ->when($filter->dateFrom, fn (Builder $q, string $v) => $q->where('created_at', '>=', $this->tenantContext->dayStart($v)))
            ->when($filter->dateTo, fn (Builder $q, string $v) => $q->where('created_at', '<', $this->tenantContext->dayStart($v)->addDay()))
            ->orderByDesc('id')
            ->paginate(ListPage::clamp($filter->perPage))
            ->withQueryString();
    }
}
