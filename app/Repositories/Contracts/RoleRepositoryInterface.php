<?php

namespace App\Repositories\Contracts;

use App\Models\Role;
use Illuminate\Support\Collection;

/**
 * Roles belonging to the current tenant.
 */
interface RoleRepositoryInterface
{
    /**
     * @return Collection<int, Role>
     */
    public function all(): Collection;

    public function find(int $id): ?Role;

    public function create(string $name): Role;

    public function rename(Role $role, string $name): Role;

    public function usersCount(Role $role): int;

    public function delete(Role $role): void;
}
