<?php

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->owner->setRelation('tenant', $this->tenant);
    Sanctum::actingAs($this->owner);
    // Added late on 5 April and just after midnight on 6 April, Dhaka time (both 5 April in UTC)
    $this->operator = tenantUser($this->tenant, 'Operator', ['name' => 'Olive Operator', 'email' => 'olive@example.com', 'phone' => '0171']);
    $this->viewer = tenantUser($this->tenant, 'Viewer', ['name' => 'Victor Viewer', 'email' => 'victor@example.com', 'status' => 'inactive']);
    User::query()->whereKey($this->operator->id)->update(['created_at' => CarbonImmutable::parse('2026-04-05 23:30', 'Asia/Dhaka')->utc(), 'locked_until' => now()->addHour()]);
    User::query()->whereKey($this->viewer->id)->update(['created_at' => CarbonImmutable::parse('2026-04-06 00:30', 'Asia/Dhaka')->utc()]);
});

it('finds the users the user list finds, added dates in the organization\'s days', function () {
    $ids = fn (string $url) => array_column($this->getJson($url)->assertOk()->json('data'), 'id');
    foreach (['', 'filter[search]=olive', 'filter[status]=inactive', 'filter[role]=Operator', 'filter[locked]=1', 'filter[locked]=0', 'filter[two_factor]=0', 'filter[created_from]=2026-04-06&filter[created_to]=2026-04-06', 'filter[created_to]=2026-04-05', 'sort=name'] as $query) {
        expect($ids("/api/v1/reports/users?{$query}"))->toBe($ids("/api/v1/users?{$query}"), $query);
    }
    // The list counted UTC days before: both were "5 April"
    expect($ids('/api/v1/users?filter[created_from]=2026-04-06&filter[created_to]=2026-04-06'))->toBe([$this->viewer->public_id])
        ->and($ids('/api/v1/users?filter[created_to]=2026-04-05'))->toBe([$this->operator->public_id]);
    $this->getJson('/api/v1/users?filter[created_from]=2026-04-06&filter[created_to]=2026-04-05')->assertJsonValidationErrors('filter.created_to');
});

it('shows each user\'s roles, status, lock and last sign-in, with totals', function () {
    $this->getJson('/api/v1/reports/users?filter[role]=Operator&columns[]=user.name&columns[]=user.email&columns[]=user.roles&columns[]=user.phone&columns[]=user.status&columns[]=user.locked&columns[]=user.two_factor')
        ->assertJsonPath('data.0.values', ['user.name' => 'Olive Operator', 'user.email' => 'olive@example.com', 'user.roles' => 'Operator', 'user.phone' => '0171', 'user.status' => 'Active', 'user.locked' => 'Yes', 'user.two_factor' => 'No']);
    $this->getJson('/api/v1/reports/users')->assertJsonPath('meta.totals', ['users' => 3, 'active' => 2, 'two_factor' => 0]);

    $roles = array_column(collect($this->getJson('/api/v1/reports')->json('data'))->keyBy('key')['users']['filters'], null, 'key')['role']['options'];
    expect(array_column($roles, 'value'))->toContain('Owner', 'Administrator', 'Operator', 'Viewer');
});

it('needs the users.view permission and downloads what it finds', function () {
    $this->get('/api/v1/reports/users/export?format=csv&filter[status]=inactive&columns[]=user.name&columns[]=user.status')
        ->assertOk()
        ->assertStreamedContent("\u{FEFF}Name,Status\n\"Victor Viewer\",Inactive\n");

    // Reports without users.view
    $role = app(TenantContext::class)->run($this->tenant, fn () => Role::create(['name' => 'Report reader', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id]));
    $role->givePermissionTo(['reports.view']);
    Sanctum::actingAs(tenantUser($this->tenant, 'Report reader'));
    $this->getJson('/api/v1/reports/users')->assertForbidden();
});

it('lists the organization\'s roles with their users and permissions', function () {
    $this->getJson('/api/v1/reports/roles?columns[]=role.name&columns[]=role.users&columns[]=role.permissions')
        ->assertOk()
        ->assertJsonPath('meta.totals.roles', 4);

    $rows = collect($this->getJson('/api/v1/reports/roles?columns[]=role.name&columns[]=role.users&columns[]=role.permissions')->json('data'))->pluck('values');
    // Keys hold dots: read them as they are
    $operator = $rows->first(fn (array $values) => $values['role.name'] === 'Operator');
    expect($rows->map(fn (array $values) => $values['role.name'])->all())->toBe(['Administrator', 'Operator', 'Owner', 'Viewer'])
        ->and($operator['role.users'])->toBe(1)
        ->and($operator['role.permissions'])->toContain('cardholders.view');
    $this->getJson('/api/v1/reports/roles?filter[search]=view')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/reports/roles?sort=-users_count')->assertOk();

    $role = app(TenantContext::class)->run($this->tenant, fn () => Role::create(['name' => 'Report reader', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id]));
    $role->givePermissionTo(['reports.view']);
    Sanctum::actingAs(tenantUser($this->tenant, 'Report reader'));
    $this->getJson('/api/v1/reports/roles')->assertForbidden();
});
