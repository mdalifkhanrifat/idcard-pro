<?php

use App\Models\Cardholder;
use App\Models\CardTemplate;
use App\Models\MasterDataItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenants\TenantService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

const TEST_PASSWORD = 'Secret#Pass123';

/**
 * Provision a tenant with default roles and return [tenant, owner].
 *
 * @return array{0: Tenant, 1: User}
 */
function tenantWithOwner(string $slug = 'acme', string $email = 'owner@example.com'): array
{
    $tenant = app(TenantService::class)->provision(
        ['name' => ucfirst($slug), 'slug' => $slug],
        ['name' => 'Owner', 'email' => $email, 'username' => 'owner', 'password' => TEST_PASSWORD],
    );

    return [$tenant, User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail()];
}

/**
 * Create a user inside a tenant with the given role.
 */
function tenantUser(Tenant $tenant, string $role = 'Operator', array $attributes = []): User
{
    return app(TenantContext::class)->run($tenant, function () use ($tenant, $role, $attributes) {
        $user = User::factory()->create([...$attributes, 'tenant_id' => $tenant->id, 'password' => TEST_PASSWORD]);

        return $user->assignRole($role);
    });
}

/**
 * Create a master data item inside a tenant.
 */
function masterItem(Tenant $tenant, array $attributes): MasterDataItem
{
    return app(TenantContext::class)->run($tenant, fn () => MasterDataItem::factory()->create([...$attributes, 'tenant_id' => $tenant->id]));
}

/**
 * Create a cardholder inside a tenant.
 */
function cardholder(Tenant $tenant, array $attributes = []): Cardholder
{
    return app(TenantContext::class)->run($tenant, fn () => Cardholder::factory()->create([...$attributes, 'tenant_id' => $tenant->id]));
}

/**
 * An active card template with one data field element.
 *
 * @param  array<string, mixed>  $attributes
 */
function cardTemplate($tenant, array $attributes = []): CardTemplate
{
    return app(TenantContext::class)->run($tenant, function () use ($tenant, $attributes) {
        $template = CardTemplate::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true, ...$attributes]);
        $template->elements()->create(['side' => 'front', 'type' => 'field', 'field' => 'full_name', 'x' => 5, 'y' => 5, 'width' => 40, 'height' => 6, 'z_index' => 0, 'style' => ['font_size' => 9]]);

        return $template;
    });
}
