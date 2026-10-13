<?php

namespace App\Repositories\Contracts;

use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Roles belonging to the current tenant.
 */
interface RoleRepositoryInterface
{
    /**
     * @return Collection<int, Role>
     */
    public function all(): Collection;

    /**
     * The organization's roles with their permissions and how many users have each (sort: name, users_count,
     * permissions_count or created_at).
     *
     * @return LengthAwarePaginator<int, Role>
     */
    public function report(?string $search, string $sort, bool $descending, int $perPage): LengthAwarePaginator;

    /**
     * @return LazyCollection<int, Role>
     */
    public function reportRows(?string $search, string $sort, bool $descending): LazyCollection;

    public function find(int $id): ?Role;

    public function create(string $name): Role;

    public function rename(Role $role, string $name): Role;

    public function usersCount(Role $role): int;

    public function delete(Role $role): void;
}
