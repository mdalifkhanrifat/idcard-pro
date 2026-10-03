<?php

namespace App\Repositories\Eloquent;

use App\Data\Users\UserFilter;
use App\Enums\UserStatus;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends EloquentRepository<User>
 */
class UserRepository extends EloquentRepository implements UserRepositoryInterface
{
    protected function model(): string
    {
        return User::class;
    }

    public function list(UserFilter $filter): LengthAwarePaginator
    {
        return $this->query()
            ->with('roles')
            ->when($filter->search, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->when($filter->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filter->role, fn (Builder $q, string $role) => $q->whereHas('roles', fn (Builder $r) => $r->where('name', $role)))
            ->when($filter->twoFactor !== null, fn (Builder $q) => $filter->twoFactor
                ? $q->whereNotNull('two_factor_confirmed_at')
                : $q->whereNull('two_factor_confirmed_at'))
            ->when($filter->locked !== null, fn (Builder $q) => $filter->locked
                ? $q->where('locked_until', '>', now())
                : $q->where(fn (Builder $w) => $w->whereNull('locked_until')->orWhere('locked_until', '<=', now())))
            ->when($filter->createdFrom, fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filter->createdTo, fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date))
            ->orderBy($filter->sort, $filter->descending ? 'desc' : 'asc')
            ->orderBy('id', 'desc')
            ->paginate(min($filter->perPage, 100))
            ->withQueryString();
    }

    public function countActiveWithRole(string $role, User $except): int
    {
        return $this->query()
            ->whereKeyNot($except->getKey())
            ->where('status', UserStatus::Active)
            ->whereHas('roles', fn (Builder $r) => $r->where('name', $role))
            ->count();
    }

    public function findForLogin(?int $tenantId, string $identifier): ?User
    {
        $column = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return User::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where($column, $column === 'email' ? strtolower($identifier) : $identifier)
            ->when(
                $tenantId,
                fn (Builder $q) => $q->where('tenant_id', $tenantId),
                fn (Builder $q) => $q->whereNull('tenant_id')->where('is_platform_admin', true),
            )
            ->first();
    }

    public function findAnyTenant(int $id): ?User
    {
        return User::query()->withoutGlobalScopes()->whereNull('deleted_at')->find($id);
    }
}
