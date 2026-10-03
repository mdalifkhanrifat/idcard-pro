<?php

namespace App\Services\Roles;

use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tenant roles and their permissions.
 */
class RoleService
{
    public function __construct(private RoleRepositoryInterface $roles) {}

    /**
     * @return Collection<int, Role>
     */
    public function list(): Collection
    {
        return $this->roles->all();
    }

    public function findOrFail(int $id): Role
    {
        return $this->roles->find($id) ?? throw new NotFoundHttpException;
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function create(string $name, array $permissions): Role
    {
        return DB::transaction(function () use ($name, $permissions): Role {
            $role = $this->roles->create($name);
            $role->syncPermissions($permissions);

            return $role->load('permissions');
        });
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function update(Role $role, string $name, array $permissions): Role
    {
        $this->ensureEditable($role);

        return DB::transaction(function () use ($role, $name, $permissions): Role {
            $this->roles->rename($role, $name);
            $role->syncPermissions($permissions);

            return $role->load('permissions');
        });
    }

    public function delete(Role $role): void
    {
        $this->ensureEditable($role);

        if ($this->roles->usersCount($role) > 0) {
            throw new BusinessRuleException(__('roles.in_use'));
        }

        $this->roles->delete($role);
    }

    private function ensureEditable(Role $role): void
    {
        if ($role->name === PermissionRegistry::OWNER_ROLE) {
            throw new BusinessRuleException(__('roles.owner_locked'));
        }
    }
}
