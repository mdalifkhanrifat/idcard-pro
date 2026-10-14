<?php

use App\Models\Role;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Tenancy\TenantContext;

/*
| Who sees the log of sent messages (notifications.view) and who sends by hand — resend, test message —
| (notifications.send): every new organization's Owner and Administrator both, the Viewer only sees, the
| Operator neither. An organization made before keeps its roles; syncing gives the new ones to its Owner only.
*/

beforeEach(function () {
    [$this->tenant] = tenantWithOwner();
    $this->abilities = fn (string $role): array => app(TenantContext::class)->run(
        $this->tenant,
        fn () => Role::findByName($role, PermissionRegistry::GUARD)->permissions->pluck('name')
            ->filter(fn (string $name) => str_starts_with($name, 'notifications.'))->sort()->values()->all(),
    );
});

it('registers notifications.view and notifications.send', function () {
    expect(config('permissions.modules.notifications'))->toBe(['view', 'send']);
});

it('gives the default roles of a new organization what was decided', function () {
    expect(($this->abilities)('Owner'))->toBe(['notifications.send', 'notifications.view'])
        ->and(($this->abilities)('Administrator'))->toBe(['notifications.send', 'notifications.view'])
        ->and(($this->abilities)('Viewer'))->toBe(['notifications.view'])
        ->and(($this->abilities)('Operator'))->toBe([]);
});

it('gives an organization made before only its Owner the new permissions when syncing', function () {
    // As if made before notifications existed
    app(TenantContext::class)->run($this->tenant, function () {
        foreach (['Owner', 'Administrator', 'Viewer'] as $role) {
            $role = Role::findByName($role, PermissionRegistry::GUARD);
            foreach ($role->permissions->pluck('name')->filter(fn (string $name) => str_starts_with($name, 'notifications.')) as $permission) {
                $role->revokePermissionTo($permission);
            }
        }
    });
    expect(($this->abilities)('Owner'))->toBe([]);

    $this->artisan('app:sync-permissions')->assertSuccessful();

    expect(($this->abilities)('Owner'))->toBe(['notifications.send', 'notifications.view'])
        ->and(($this->abilities)('Administrator'))->toBe([])
        ->and(($this->abilities)('Viewer'))->toBe([]);
});

it('names every registered module and ability in both languages of the roles page', function () {
    foreach (['en', 'bn'] as $locale) {
        $roles = json_decode(file_get_contents(resource_path("js/locales/{$locale}.json")), true)['roles'];

        foreach (config('permissions.modules') as $module => $abilities) {
            expect($roles['modules'])->toHaveKey($module);
            foreach ($abilities as $ability) {
                expect($roles['abilities'])->toHaveKey($ability);
            }
        }
    }
});
