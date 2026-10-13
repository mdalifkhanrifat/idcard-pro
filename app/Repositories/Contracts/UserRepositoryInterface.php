<?php

namespace App\Repositories\Contracts;

use App\Data\Users\UserFilter;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

/**
 * @extends RepositoryInterface<User>
 */
interface UserRepositoryInterface extends RepositoryInterface
{
    /**
     * Users of the current tenant.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function list(UserFilter $filter): LengthAwarePaginator;

    /**
     * Users of the report (the list's criteria) with their roles.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function report(UserFilter $filter): LengthAwarePaginator;

    /**
     * @return LazyCollection<int, User>
     */
    public function reportRows(UserFilter $filter): LazyCollection;

    /**
     * @return array{users: int, active: int, two_factor: int}
     */
    public function reportTotals(UserFilter $filter): array;

    /**
     * Number of active users holding the role, excluding the given user.
     */
    public function countActiveWithRole(string $role, User $except): int;

    /**
     * Find a sign-in candidate by e-mail or username, ignoring the current tenant scope.
     * A null tenant id means a platform administrator.
     */
    public function findForLogin(?int $tenantId, string $identifier): ?User;

    /**
     * Find by primary key across all tenants (used before a tenant is bound).
     */
    public function findAnyTenant(int $id): ?User;
}
