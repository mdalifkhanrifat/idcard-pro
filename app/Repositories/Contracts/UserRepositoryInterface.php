<?php

namespace App\Repositories\Contracts;

use App\Data\Users\UserFilter;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
     * Number of active users holding the role, excluding the given user.
     */
    public function countActiveWithRole(string $role, User $except): int;
}
