<?php

namespace App\Repositories\Contracts;

use App\Data\Audit\LoginEventFilter;
use App\Enums\LoginChannel;
use App\Enums\LoginEventType;
use App\Models\Cardholder;
use App\Models\LoginEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

interface LoginEventRepositoryInterface
{
    public function record(LoginEventType $event, ?string $ipAddress, ?string $userAgent, ?User $user = null, ?Tenant $tenant = null, ?string $identifier = null, LoginChannel $channel = LoginChannel::Staff, ?Cardholder $cardholder = null): LoginEvent;

    /**
     * Sign-in events of the current tenant (all events when no tenant is bound).
     *
     * @return LengthAwarePaginator<int, LoginEvent>
     */
    public function list(LoginEventFilter $filter): LengthAwarePaginator;

    /**
     * @return LazyCollection<int, LoginEvent>
     */
    public function reportRows(LoginEventFilter $filter): LazyCollection;

    /**
     * Sign-in events, successful sign-ins and distinct addresses over every page.
     *
     * @return array{events: int, succeeded: int, addresses: int}
     */
    public function reportTotals(LoginEventFilter $filter): array;
}
