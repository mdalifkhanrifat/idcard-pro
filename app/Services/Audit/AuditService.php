<?php

namespace App\Services\Audit;

use App\Data\Audit\ActivityFilter;
use App\Data\Audit\LoginEventFilter;
use App\Enums\LoginEventType;
use App\Models\LoginEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Repositories\Contracts\LoginEventRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * Data change history and sign-in history.
 */
class AuditService
{
    public function __construct(
        private ActivityRepositoryInterface $activities,
        private LoginEventRepositoryInterface $loginEvents,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Activity>
     */
    public function activities(ActivityFilter $filter): LengthAwarePaginator
    {
        return $this->activities->list($filter);
    }

    /**
     * @return array<int, string>
     */
    public function logNames(): array
    {
        return $this->activities->logNames();
    }

    /**
     * @return LengthAwarePaginator<int, LoginEvent>
     */
    public function logins(LoginEventFilter $filter): LengthAwarePaginator
    {
        return $this->loginEvents->list($filter);
    }

    public function recordLogin(LoginEventType $event, Request $request, ?User $user = null, ?Tenant $tenant = null, ?string $identifier = null): LoginEvent
    {
        return $this->loginEvents->record($event, $request->ip(), $request->userAgent(), $user, $tenant, $identifier);
    }
}
