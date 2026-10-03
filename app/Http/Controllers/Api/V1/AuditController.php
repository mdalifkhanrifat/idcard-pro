<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AuditController extends Controller
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Data change history. Filters: filter[log_name], filter[event], filter[causer], filter[date_from], filter[date_to] (Y-m-d)
     */
    public function activities(Request $request): JsonResponse
    {
        Gate::authorize('audit.view');

        $tenantUserIds = User::query()->pluck('id');

        $page = QueryBuilder::for(Activity::class)
            ->allowedFilters(
                AllowedFilter::exact('log_name'),
                AllowedFilter::exact('event'),
                AllowedFilter::callback('causer', fn (Builder $q, string $v) => $q->whereIn('causer_id', User::query()->where('public_id', $v)->pluck('id'))),
                ...$this->dateRange(),
            )
            ->defaultSort('-id')
            ->with('causer:id,name,email')
            ->where(fn (Builder $q) => $q->whereIn('causer_id', $tenantUserIds)->where('causer_type', (new User)->getMorphClass()))
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json($page->through(fn (Activity $a) => [
            'id' => $a->id,
            'log_name' => $a->log_name,
            'event' => $a->event,
            'description' => $a->description,
            'subject_type' => class_basename((string) $a->subject_type),
            'causer' => $a->causer?->only(['name', 'email']),
            'changes' => $a->attribute_changes ?? $a->properties,
            'created_at' => $a->created_at,
        ]));
    }

    /**
     * Sign-in history. Filters: filter[event], filter[identifier], filter[ip_address], filter[date_from], filter[date_to] (Y-m-d)
     */
    public function logins(Request $request): JsonResponse
    {
        Gate::authorize('audit.view');

        $page = QueryBuilder::for(LoginEvent::class)
            ->allowedFilters(
                AllowedFilter::exact('event'),
                AllowedFilter::partial('identifier'),
                AllowedFilter::exact('ip_address'),
                ...$this->dateRange(),
            )
            ->defaultSort('-id')
            ->with('user:id,name,email')
            ->when($this->tenantContext->has(), fn (Builder $q) => $q->where('tenant_id', $this->tenantContext->id()))
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json($page->through(fn (LoginEvent $e) => [
            'id' => $e->id,
            'event' => $e->event,
            'identifier' => $e->identifier,
            'user' => $e->user?->only(['name', 'email']),
            'ip_address' => $e->ip_address,
            'user_agent' => $e->user_agent,
            'created_at' => $e->created_at,
        ]));
    }

    /**
     * @return array<int, AllowedFilter>
     */
    private function dateRange(): array
    {
        return [
            AllowedFilter::callback('date_from', fn (Builder $query, string $value) => $query->whereDate('created_at', '>=', $value)),
            AllowedFilter::callback('date_to', fn (Builder $query, string $value) => $query->whereDate('created_at', '<=', $value)),
        ];
    }
}
