<?php

namespace App\Services\Audit;

use App\Data\Audit\ActivityFilter;
use App\Data\Audit\LoginEventFilter;
use App\Enums\LoginChannel;
use App\Enums\LoginEventType;
use App\Models\Cardholder;
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
     * @return array<int, string>
     */
    public function events(): array
    {
        return $this->activities->events();
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

    /**
     * The ways one signs in, named in the reader's language (the sign-ins filter).
     *
     * @return array<int, array{key: string, name: string}>
     */
    public function loginChannels(): array
    {
        return array_map(fn (LoginChannel $channel) => ['key' => $channel->value, 'name' => $channel->label()], LoginChannel::cases());
    }

    /**
     * A cardholder proving who they are on the public booking link (5.6e): signed in, locked out (no tries left)
     * or blocked (too many tries for one employee number) — by the employee number typed, and the cardholder when
     * known (an unknown number is kept as typed).
     */
    public function recordPublicSignIn(LoginEventType $event, Request $request, Tenant $tenant, string $employeeNo, ?Cardholder $cardholder = null): LoginEvent
    {
        return $this->loginEvents->record($event, $request->ip(), $request->userAgent(), null, $tenant, $employeeNo, LoginChannel::PublicBooking, $cardholder);
    }
}
