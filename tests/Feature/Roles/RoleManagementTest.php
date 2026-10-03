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
