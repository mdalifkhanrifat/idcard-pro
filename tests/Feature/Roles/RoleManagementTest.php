<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    Sanctum::actingAs($this->owner);
});

function roleId(Tenant $tenant, string $name): int
{
    return (int) DB::table('roles')->where('tenant_id', $tenant->id)->where('name', $name)->value('id');
}

it('creates, renames and deletes a role with permissions', function () {
    $id = $this->postJson('/api/v1/roles', ['name' => 'Card Printer', 'permissions' => ['users.view', 'master_data.view']])
        ->assertCreated()
        ->assertJsonPath('data.permissions', ['users.view', 'master_data.view'])
        ->json('data.id');

    $this->putJson("/api/v1/roles/{$id}", ['name' => 'Card Operator', 'permissions' => ['users.view']])
        ->assertOk()
        ->assertJsonPath('data.name', 'Card Operator')
        ->assertJsonPath('data.permissions', ['users.view']);

    $this->deleteJson("/api/v1/roles/{$id}")->assertNoContent();
});

it('rejects unknown permissions and duplicate names', function () {
    $this->postJson('/api/v1/roles', ['name' => 'X', 'permissions' => ['nuclear.launch']])->assertJsonValidationErrors('permissions.0');
    $this->postJson('/api/v1/roles', ['name' => 'Viewer'])->assertJsonValidationErrors('name');
});

it('protects the Owner role', function () {
    $ownerRole = roleId($this->tenant, 'Owner');

    $this->putJson("/api/v1/roles/{$ownerRole}", ['name' => 'Boss'])->assertUnprocessable()->assertJson(['message' => __('roles.owner_locked')]);
    $this->deleteJson("/api/v1/roles/{$ownerRole}")->assertUnprocessable();
});

it('refuses to delete a role that is assigned to users', function () {
    tenantUser($this->tenant, 'Viewer');

    $this->deleteJson('/api/v1/roles/'.roleId($this->tenant, 'Viewer'))->assertUnprocessable()->assertJson(['message' => __('roles.in_use')]);
});

it('notes every role change and every user\'s roles in the audit log: only what changed, nothing when nothing did', function () {
    $audit = fn (string $log) => collect($this->getJson("/api/v1/audit/activities?filter[log_name]={$log}&per_page=100")->json('data'))->reverse()->values();

    $id = $this->postJson('/api/v1/roles', ['name' => 'Card Printer', 'permissions' => ['users.view', 'master_data.view']])->assertCreated()->json('data.id');
    $this->putJson("/api/v1/roles/{$id}", ['name' => 'Card Printer', 'permissions' => ['master_data.view', 'cards.print']])->assertOk();
    // The same again, in another order: nothing changed
    $this->putJson("/api/v1/roles/{$id}", ['name' => 'Card Printer', 'permissions' => ['cards.print', 'master_data.view']])->assertOk();
    $this->putJson("/api/v1/roles/{$id}", ['name' => 'Printer', 'permissions' => ['cards.print', 'master_data.view']])->assertOk();
    $this->deleteJson("/api/v1/roles/{$id}")->assertNoContent();

    $roles = $audit('roles');
    expect($roles->pluck('event')->all())->toBe(['created', 'updated', 'updated', 'deleted'])
        ->and($roles->pluck('causer.email')->unique()->all())->toBe([$this->owner->email])
        ->and($roles[0]['changes'])->toBe(['attributes' => ['name' => 'Card Printer', 'permissions' => ['master_data.view', 'users.view']]])
        ->and($roles[1]['changes'])->toBe(['attributes' => ['permissions' => ['cards.print', 'master_data.view']], 'old' => ['permissions' => ['master_data.view', 'users.view']]])
        ->and($roles[2]['changes'])->toBe(['attributes' => ['name' => 'Printer'], 'old' => ['name' => 'Card Printer']])
        ->and($roles[3]['changes'])->toBe(['old' => ['name' => 'Printer', 'permissions' => ['cards.print', 'master_data.view']]]);

    // A user's roles given and taken
    $user = tenantUser($this->tenant, 'Viewer');
    $this->putJson("/api/v1/users/{$user->public_id}", ['name' => $user->name, 'email' => $user->email, 'status' => 'active', 'roles' => ['Viewer', 'Operator']])->assertOk();
    $this->putJson("/api/v1/users/{$user->public_id}", ['name' => $user->name, 'email' => $user->email, 'status' => 'active', 'roles' => ['Operator', 'Viewer']])->assertOk();
    $roleChanges = $audit('user')->filter(fn (array $row) => isset($row['changes']['attributes']['roles']))->values();
    expect($roleChanges)->toHaveCount(1)
        ->and($roleChanges[0]['changes'])->toBe(['attributes' => ['roles' => ['Operator', 'Viewer']], 'old' => ['roles' => ['Viewer']]]);
});
