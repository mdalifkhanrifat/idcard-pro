<?php

namespace App\Repositories\Eloquent;

use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Support\ListPage;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Roles are owned by spatie/laravel-permission, which scopes them by `tenant_id` (team) itself,
 * so this repository applies the tenant filter explicitly instead of using BelongsToTenant.
 */
class RoleRepository implements RoleRepositoryInterface
{
    public function __construct(private TenantContext $tenantContext) {}

    public function all(): Collection
    {
        return $this->query()->with('permissions')->withCount('users')->orderBy('name')->get();
    }

    public function report(?string $search, string $sort, bool $descending, int $perPage): LengthAwarePaginator
    {
        return $this->reportQuery($search, $sort, $descending)->paginate(ListPage::clamp($perPage))->withQueryString();
    }

    public function reportRows(?string $search, string $sort, bool $descending): LazyCollection
    {
        return $this->reportQuery($search, $sort, $descending)->lazy(200);
    }

    /**
     * @return Builder<Role>
     */
    private function reportQuery(?string $search, string $sort, bool $descending): Builder
    {
        return $this->query()
            ->with('permissions')
            ->withCount(['users', 'permissions'])
            ->when($search, fn (Builder $q, string $value) => $q->where('name', 'like', "%{$value}%"))
            ->orderBy($sort, $descending ? 'desc' : 'asc')
            ->orderBy('id');
    }

    public function find(int $id): ?Role
    {
        return $this->query()->find($id);
    }

    public function create(string $name): Role
    {
        return Role::query()->create(['name' => $name, 'guard_name' => PermissionRegistry::GUARD, 'tenant_id' => $this->tenantContext->id()]);
    }

    public function rename(Role $role, string $name): Role
    {
        $role->update(['name' => $name]);

        return $role;
    }

    public function usersCount(Role $role): int
    {
        return $role->users()->count();
    }

    public function delete(Role $role): void
    {
        $role->delete();
    }

    /**
     * @return Builder<Role>
     */
    private function query(): Builder
    {
        return Role::query()->where('tenant_id', $this->tenantContext->id());
    }
}
