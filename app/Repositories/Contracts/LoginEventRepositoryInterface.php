<?php

namespace App\Repositories\Contracts;

use App\Data\Audit\LoginEventFilter;
use App\Enums\LoginEventType;
use App\Models\LoginEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface LoginEventRepositoryInterface
{
    public function record(LoginEventType $event, ?string $ipAddress, ?string $userAgent, ?User $user = null, ?Tenant $tenant = null, ?string $identifier = null): LoginEvent;

    /**
     * Sign-in events of the current tenant (all events when no tenant is bound).
     *
     * @return LengthAwarePaginator<int, LoginEvent>
     */
    public function list(LoginEventFilter $filter): LengthAwarePaginator;
}
