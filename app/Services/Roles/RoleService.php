<?php

namespace App\Services\Roles;

use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Support\Audit\RecordedChanges;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tenant roles and their permissions. Every role made, renamed, given or taken permissions, or deleted is in the
 * audit log (log "roles"; permissions live in a pivot table the model's own logging does not see).
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
            RecordedChanges::log('roles', $role, 'created', [], $this->described($role->load('permissions')));

            return $role;
        });
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function update(Role $role, string $name, array $permissions): Role
    {
        $this->ensureEditable($role);

        return DB::transaction(function () use ($role, $name, $permissions): Role {
            $before = $this->described($role->load('permissions'));
            $this->roles->rename($role, $name);
            $role->syncPermissions($permissions);
            RecordedChanges::log('roles', $role, 'updated', $before, $this->described($role->load('permissions')));

            return $role;
        });
    }

    public function delete(Role $role): void
    {
        $this->ensureEditable($role);

        if ($this->roles->usersCount($role) > 0) {
            throw new BusinessRuleException(__('roles.in_use'));
        }

        $before = $this->described($role->load('permissions'));
        $this->roles->delete($role);
        RecordedChanges::log('roles', $role, 'deleted', $before, []);
    }

    /**
     * @return array{name: string, permissions: array<int, string>}
     */
    private function described(Role $role): array
    {
        return ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->all()];
    }

    private function ensureEditable(Role $role): void
    {
        if ($role->name === PermissionRegistry::OWNER_ROLE) {
            throw new BusinessRuleException(__('roles.owner_locked'));
        }
    }
}
