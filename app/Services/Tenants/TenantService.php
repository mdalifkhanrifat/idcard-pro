<?php

namespace App\Services\Tenants;

use App\Data\Tenants\TenantFilter;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Biometrics\BiometricSettings;
use App\Services\MasterData\MasterDataService;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Organizations: provisioning, settings and platform-level status.
 */
class TenantService
{
    public function __construct(
        private TenantRepositoryInterface $tenants,
        private RoleRepositoryInterface $roles,
        private UserRepositoryInterface $users,
        private PermissionRegistry $permissions,
        private MasterDataService $masterData,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Tenant>
     */
    public function list(TenantFilter $filter): LengthAwarePaginator
    {
        return $this->tenants->list($filter);
    }

    public function findBySlug(string $slug): ?Tenant
    {
        return $this->tenants->findBySlug($slug);
    }

    /**
     * @param  callable(Tenant): void  $callback
     */
    public function each(callable $callback): void
    {
        $this->tenants->each($callback);
    }

    /**
     * Create an organization with its default roles, default master data and first Owner.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array{name: string, email: string, password: string, username?: string|null}  $owner
     */
    public function provision(array $attributes, array $owner): Tenant
    {
        $this->permissions->sync();

        return DB::transaction(function () use ($attributes, $owner): Tenant {
            $tenant = $this->tenants->create([...$attributes, 'slug' => strtolower($attributes['slug'])]);

            $this->tenantContext->run($tenant, function () use ($tenant, $owner): void {
                foreach (array_keys(config('permissions.default_roles')) as $roleName) {
                    $this->roles->create($roleName)->syncPermissions($this->permissions->forRole($roleName));
                }

                /** @var User $user */
                $user = $this->users->create([...$owner, 'email' => strtolower($owner['email']), 'tenant_id' => $tenant->id]);
                $user->forceFill(['password_changed_at' => now(), 'email_verified_at' => now()])->save();
                $user->assignRole(PermissionRegistry::OWNER_ROLE);
            });

            $this->masterData->seedDefaults($tenant);

            return $tenant;
        });
    }

    /**
     * Update branding and regional settings, optionally replacing the logo.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(Tenant $tenant, array $data, ?UploadedFile $logo = null): Tenant
    {
        if ($logo !== null) {
            $data['logo_path'] = $logo->store("tenants/{$tenant->public_id}/branding", 'public');
        }
        // Card settings live in the settings JSON next to other organization preferences.
        foreach (['card_number_format', 'default_card_size', 'card_number_on_reissue'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = $key === 'default_card_size' ? array_map(floatval(...), $data[$key]) : $data[$key];
                $data['settings'] = [...($data['settings'] ?? $tenant->settings ?? []), $key => $value];
                unset($data[$key]);
            }
        }

        if (array_key_exists('verification', $data)) {
            $verification = $data['verification'];
            $data['settings'] = [...($data['settings'] ?? $tenant->settings ?? []), 'verification' => [
                'enabled' => (bool) $verification['enabled'],
                'fields' => array_values(array_unique((array) ($verification['fields'] ?? []))),
                'show_revoke_reason' => (bool) ($verification['show_revoke_reason'] ?? false),
                'link' => filled($verification['link'] ?? null) ? trim((string) $verification['link']) : null,
                'note' => filled($verification['note'] ?? null) ? trim((string) $verification['note']) : null,
                'log_checks' => (bool) ($verification['log_checks'] ?? true),
            ]];
            unset($data['verification']);
        }

        if (array_key_exists('biometrics', $data)) {
            $current = (array) ($tenant->settings['biometrics'] ?? []);
            $data['settings'] = [...($data['settings'] ?? $tenant->settings ?? []), 'biometrics' => BiometricSettings::normalize(BiometricSettings::merge($current, (array) $data['biometrics']))];
            unset($data['biometrics']);
        }

        return $this->tenants->update($tenant, $data)->refresh();
    }

    /**
     * Platform-level changes (name, status).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Tenant $tenant, array $data): Tenant
    {
        return $this->tenants->update($tenant, $data);
    }
}
