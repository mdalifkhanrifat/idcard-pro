<?php

namespace Database\Seeders;

use App\Actions\Tenancy\ProvisionTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Local development data: one platform administrator and a demo organization.
 */
class DatabaseSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Demo@Pass2026!';

    public function run(PermissionRegistry $permissions, ProvisionTenant $provision, TenantContext $tenantContext): void
    {
        $permissions->sync();

        $admin = User::query()->withoutGlobalScopes()->firstOrNew(['email' => 'platform@idcard.local', 'tenant_id' => null]);
        $admin->fill(['name' => 'Platform Admin', 'username' => 'platform', 'password' => self::DEMO_PASSWORD])->forceFill(['is_platform_admin' => true, 'password_changed_at' => now()])->save();

        if (Tenant::query()->where('slug', 'acme')->doesntExist()) {
            $tenant = $provision->handle(
                ['name' => 'Acme Corporation', 'slug' => 'acme', 'legal_name' => 'Acme Corporation Ltd.', 'country_code' => 'BD', 'timezone' => 'Asia/Dhaka', 'locale' => 'en', 'primary_color' => '#0F62FE'],
                ['name' => 'Acme Owner', 'email' => 'owner@acme.test', 'username' => 'owner', 'password' => self::DEMO_PASSWORD],
            );

            $tenantContext->run($tenant, function (): void {
                User::factory()->create(['name' => 'Acme Operator', 'email' => 'operator@acme.test', 'username' => 'operator', 'password' => self::DEMO_PASSWORD, 'tenant_id' => app(TenantContext::class)->id()])
                    ->assignRole('Operator');
                User::factory(18)->create(['tenant_id' => app(TenantContext::class)->id()])
                    ->each(fn (User $user) => $user->assignRole(fake()->randomElement(['Administrator', 'Operator', 'Viewer'])));
            });
        }

        $this->call(DemoMasterDataSeeder::class);

        $this->command?->table(['Login', 'Organization', 'Password'], [
            ['platform@idcard.local', '(leave empty)', self::DEMO_PASSWORD],
            ['owner@acme.test', 'acme', self::DEMO_PASSWORD],
            ['operator@acme.test', 'acme', self::DEMO_PASSWORD],
        ]);
    }
}
