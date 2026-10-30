<?php

namespace App\Services\Roles;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Repositories\Contracts\RoleRepositoryInterface;

/**
 * No one hands on more than they hold. Whoever may manage users, roles or integrations can give only what they have
 * themselves, and cannot take over someone stronger: otherwise any such permission would quietly be all of them.
 */
class PermissionCeiling
{
    public function __construct(private RoleRepositoryInterface $roles) {}

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
