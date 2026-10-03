<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenantA, $this->ownerA] = tenantWithOwner('alpha', 'a@example.com');
    [$this->tenantB, $this->ownerB] = tenantWithOwner('beta', 'b@example.com');
    $this->userB = tenantUser($this->tenantB, 'Viewer', ['email' => 'viewer@beta.test']);
});

it('lists only users of the current organization', function () {
    Sanctum::actingAs($this->ownerA);

    $this->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'a@example.com');
});

it('cannot read, update or delete a user of another organization', function () {
    Sanctum::actingAs($this->ownerA);
    $url = '/api/v1/users/'.$this->userB->public_id;

    $this->getJson($url)->assertNotFound();
    $this->putJson($url, ['name' => 'x', 'email' => 'x@x.test', 'status' => 'active'])->assertNotFound();
    $this->deleteJson($url)->assertNotFound();

    expect($this->userB->fresh()->name)->not->toBe('x');
});

it('cannot see or assign roles of another organization', function () {
    Sanctum::actingAs($this->ownerA);

    $roleIdB = DB::table('roles')->where('tenant_id', $this->tenantB->id)->where('name', 'Viewer')->value('id');
    $this->deleteJson("/api/v1/roles/{$roleIdB}")->assertNotFound();

    expect(collect($this->getJson('/api/v1/roles')->json('data'))->pluck('name')->sort()->values()->all())
        ->toBe(['Administrator', 'Operator', 'Owner', 'Viewer']);
});

it('lets a platform admin act inside an organization via the X-Tenant header', function () {
    Sanctum::actingAs(User::factory()->platformAdmin()->create());

    $this->getJson('/api/v1/users')->assertStatus(409);
    $this->withHeader('X-Tenant', 'beta')->getJson('/api/v1/users')->assertOk()->assertJsonCount(2, 'data');
});

it('denies platform endpoints to tenant users', function () {
    Sanctum::actingAs($this->ownerA);

    $this->getJson('/api/v1/platform/tenants')->assertForbidden();
});
