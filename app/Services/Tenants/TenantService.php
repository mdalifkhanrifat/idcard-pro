<?php

namespace App\Services\Tenants;

use App\Data\Tenants\TenantFilter;
use App\Jobs\RefreshBiometricStatuses;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Biometrics\BiometricSettings;
use App\Services\MasterData\MasterDataService;
use App\Services\Notifications\NotificationSettings;
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
     * The columns a report shows by default in this organization (settings.reports.<report>.columns); null goes
     * back to the report's own default. Kept in the organization's audit log like any other setting.
     *
     * @param  array<int, string>|null  $columns
     */
    public function updateReportColumns(Tenant $tenant, string $report, ?array $columns): Tenant
    {
        $settings = $tenant->settings ?? [];
        $reports = (array) ($settings['reports'] ?? []);
        if ($columns === null) {
            unset($reports[$report]);
        } else {
            $reports[$report] = [...(array) ($reports[$report] ?? []), 'columns' => array_values($columns)];
        }
        if ($reports === []) {
            unset($settings['reports']);
        } else {
            $settings['reports'] = $reports;
        }

        return $this->tenants->update($tenant, ['settings' => $settings])->refresh();
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

        if (array_key_exists('notifications', $data)) {
            $current = (array) ($tenant->settings['notifications'] ?? []);
            $data['settings'] = [...($data['settings'] ?? $tenant->settings ?? []), 'notifications' => NotificationSettings::normalize(NotificationSettings::merge($current, (array) $data['notifications']))];
            unset($data['notifications']);
        }

        $biometricsChanged = false;
        if (array_key_exists('biometrics', $data)) {
            $current = (array) ($tenant->settings['biometrics'] ?? []);
            $biometrics = BiometricSettings::normalize(BiometricSettings::merge($current, (array) $data['biometrics']));
            // Only what decides a person's status (not when it is required or whether it prints)
            $decides = fn (array $settings): array => array_intersect_key($settings, array_flip(['enabled', 'modalities', 'family_members']));
            $biometricsChanged = $decides($biometrics) !== $decides($tenant->biometricSettings());
            $data['settings'] = [...($data['settings'] ?? $tenant->settings ?? []), 'biometrics' => $biometrics];
            unset($data['biometrics']);
        }

        $tenant = $this->tenants->update($tenant, $data)->refresh();
        if ($biometricsChanged) {
            // Everybody's status follows the new rules (in the background: organizations can be large)
            RefreshBiometricStatuses::dispatch($tenant->id)->afterCommit();
        }

        return $tenant;
    }

    /**
     * A partial change to the organization's notification settings (JSON clients): what is not sent stays. Saved
     * as any setting, so the audit log notes each changed one.
     *
     * @param  array<string, mixed>  $change
     */
    public function updateNotificationSettings(Tenant $tenant, array $change): Tenant
    {
        $current = (array) ($tenant->settings['notifications'] ?? []);

        return $this->updateSettings($tenant, ['settings' => [...($tenant->settings ?? []), 'notifications' => NotificationSettings::normalize(NotificationSettings::patch($current, $change))]]);
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
