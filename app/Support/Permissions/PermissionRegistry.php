<?php

namespace App\Support\Permissions;

use App\Models\Role;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reads the permission registry in config/permissions.php and keeps the database in sync with it.
 */
class PermissionRegistry
{
    public const GUARD = 'web';

    /** Built-in role that always holds every permission and cannot be edited or deleted. */
    public const OWNER_ROLE = 'Owner';

    /**
     * @return Collection<int, non-falsy-string>
     */
    public function all(): Collection
    {
        return collect(config('permissions.modules'))
            ->flatMap(fn (array $abilities, string $module) => collect($abilities)->map(fn (string $ability): string => "{$module}.{$ability}"))
            ->values();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function grouped(): array
    {
        return config('permissions.modules');
    }

    /**
     * @return Collection<int, string>
     */
    public function forRole(string $role): Collection
    {
        $granted = config("permissions.default_roles.{$role}", []);

        return $granted === '*' ? $this->all() : collect($granted);
    }

    public function sync(): int
    {
        $names = $this->all();

        $names->each(fn (string $name) => Permission::findOrCreate($name, self::GUARD));

        Permission::query()->where('guard_name', self::GUARD)->whereNotIn('name', $names)->delete();

        // Every tenant's Owner always holds every permission, including ones added by new modules.
        Role::query()->where('name', self::OWNER_ROLE)->where('guard_name', self::GUARD)
            ->each(fn (Role $owner) => $owner->syncPermissions($names));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $names->count();
    }
}
