<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
});

it('creates a user with roles', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/v1/users', [
        'name' => 'Jane Operator',
        'email' => 'Jane@Example.com',
        'status' => 'active',
        'password' => 'Strong#Pass789',
        'password_confirmation' => 'Strong#Pass789',
        'roles' => ['Operator'],
    ])->assertCreated()->assertJsonPath('data.email', 'jane@example.com')->assertJsonPath('data.roles', ['Operator']);

    expect(User::where('email', 'jane@example.com')->first()->tenant_id)->toBe($this->tenant->id);
});

it('enforces permissions', function () {
    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));

    $this->getJson('/api/v1/users')->assertOk();
    $this->postJson('/api/v1/users', ['name' => 'x'])->assertForbidden();
    $this->getJson('/api/v1/roles')->assertForbidden();
});

it('filters and sorts users', function () {
    tenantUser($this->tenant, 'Viewer', ['name' => 'Zed Viewer', 'email' => 'zed@example.com']);
    tenantUser($this->tenant, 'Operator', ['name' => 'Amy Locked', 'locked_until' => now()->addHour()]);
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/users?filter[role]=Viewer')->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'zed@example.com');
    $this->getJson('/api/v1/users?filter[search]=zed')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/users?filter[locked]=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Amy Locked');
    $this->getJson('/api/v1/users?sort=name')->assertJsonPath('data.0.name', 'Amy Locked');
    $this->getJson('/api/v1/users?filter[unknown]=1')->assertJsonValidationErrors('filter');
    $this->getJson('/api/v1/users?sort=password')->assertJsonValidationErrors('sort');
});

it('keeps at least one active owner', function () {
    Sanctum::actingAs($this->owner);

    $this->putJson('/api/v1/users/'.$this->owner->public_id, [
        'name' => 'Owner', 'email' => 'owner@example.com', 'status' => 'active', 'roles' => ['Viewer'],
    ])->assertUnprocessable();

    $this->deleteJson('/api/v1/users/'.$this->owner->public_id)->assertUnprocessable();
});

it('unlocks a locked user', function () {
    $locked = tenantUser($this->tenant, 'Operator', ['locked_until' => now()->addHour(), 'failed_login_attempts' => 3]);
    Sanctum::actingAs($this->owner);

    $this->putJson('/api/v1/users/'.$locked->public_id, [
        'name' => $locked->name, 'email' => $locked->email, 'status' => 'active', 'unlock' => true,
    ])->assertOk()->assertJsonPath('data.is_locked', false);
});

it('filters by creation date range', function () {
    tenantUser($this->tenant, 'Viewer', ['email' => 'old@example.com', 'created_at' => '2025-01-15 10:00:00']);
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/users?filter[created_to]=2025-12-31')->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'old@example.com');
    $this->getJson('/api/v1/users?filter[created_from]=2025-12-31')->assertJsonMissing(['email' => 'old@example.com']);
    $this->getJson('/api/v1/users?filter[created_from]=31-12-2025')->assertJsonValidationErrors('filter.created_from');
});
