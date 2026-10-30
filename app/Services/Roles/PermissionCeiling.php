<?php

namespace App\Services\Roles;

use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Models\User;
use App\Repositories\Contracts\RoleRepositoryInterface;
use Spatie\Permission\Models\Permission;

/**
 * No one hands on more than they hold. Whoever may manage users, roles or integrations can give only what they have
 * themselves, and cannot take over someone stronger: otherwise any such permission would quietly be all of them.
 */
class PermissionCeiling
{
    public function __construct(private RoleRepositoryInterface $roles) {}

    /**
     * The organization's roles the actor can hand on, for a page to offer only those.
     *
     * @return array<int, string>
     */
    public function assignableRoles(User $actor): array
    {
        return $this->roles->all()
            ->filter(fn (Role $role) => $role->permissions->every(fn (Permission $permission) => $actor->can($permission->name)))
            ->pluck('name')->values()->all();
    }

    /**
     * @param  array<int, string>  $roles  role names
     */
    public function ensureRoles(User $actor, array $roles): void
    {
        $this->ensurePermissions($actor, $this->roles->permissionNames($roles));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function ensurePermissions(User $actor, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (! $actor->can($permission)) {
                throw new BusinessRuleException(__('roles.beyond_own'));
            }
        }
    }
}
