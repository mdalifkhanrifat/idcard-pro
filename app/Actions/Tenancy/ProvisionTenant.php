<?php

namespace App\Actions\Tenancy;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MasterData\MasterDataService;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Creates a tenant together with its default roles and first owner account.
 */
class ProvisionTenant
{
    public function __construct(
        private PermissionRegistry $permissions,
        private TenantContext $tenantContext,
        private MasterDataService $masterData,
    ) {}

    /**
     * @param  array<string, mixed>  $tenantAttributes
     * @param  array{name: string, email: string, password: string, username?: string|null}  $owner
     */
    public function handle(array $tenantAttributes, array $owner): Tenant
    {
        $this->permissions->sync();

        return DB::transaction(function () use ($tenantAttributes, $owner): Tenant {
            $tenant = Tenant::create($tenantAttributes);

            $this->tenantContext->run($tenant, function () use ($tenant, $owner): void {
                foreach (array_keys(config('permissions.default_roles')) as $roleName) {
                    Role::create(['name' => $roleName, 'guard_name' => PermissionRegistry::GUARD, 'tenant_id' => $tenant->id])
                        ->syncPermissions($this->permissions->forRole($roleName));
                }

                $user = User::create([...$owner, 'tenant_id' => $tenant->id]);
                $user->forceFill(['password_changed_at' => now(), 'email_verified_at' => now()])->save();
                $user->assignRole(PermissionRegistry::OWNER_ROLE);
            });

            $this->masterData->seedDefaults($tenant);

            return $tenant;
        });
    }
}
