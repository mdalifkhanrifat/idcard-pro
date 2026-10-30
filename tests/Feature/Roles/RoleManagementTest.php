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

it('lets no one put into a role a permission they do not hold themselves', function () {
    $editor = $this->postJson('/api/v1/roles', ['name' => 'Role Editor', 'permissions' => ['roles.view', 'roles.create', 'roles.update', 'users.view']])->assertCreated()->json('data.id');
    $someone = $this->postJson('/api/v1/roles', ['name' => 'Someone', 'permissions' => ['users.view', 'settings.update']])->assertCreated()->json('data.id');
    Sanctum::actingAs(tenantUser($this->tenant, 'Role Editor'));

    $this->postJson('/api/v1/roles', ['name' => 'Bigger', 'permissions' => ['settings.update']])
        ->assertUnprocessable()->assertJsonPath('message', __('roles.beyond_own'));
    $this->putJson("/api/v1/roles/{$editor}", ['name' => 'Role Editor', 'permissions' => ['roles.view', 'roles.create', 'roles.update', 'users.view', 'settings.update']])->assertUnprocessable();

    // What a role already had is not "given" again, and what they hold they can give
    $this->putJson("/api/v1/roles/{$someone}", ['name' => 'Renamed', 'permissions' => ['users.view', 'settings.update']])->assertOk();
    $this->postJson('/api/v1/roles', ['name' => 'Smaller', 'permissions' => ['users.view']])->assertCreated();
});
