<?php

use App\Models\Cardholder;
use App\Models\MasterDataItem;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
});

it('seeds default blood groups for a new organization', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/master-data/blood_group?per_page=all')
        ->assertOk()
        ->assertJsonCount(8, 'data')
        ->assertJsonPath('data.0.name', 'A+');
});

it('lists every registered type with counts', function () {
    Sanctum::actingAs($this->owner);

    $types = collect($this->getJson('/api/v1/master-data')->assertOk()->json('data'))->keyBy('type');

    expect($types->keys()->all())->toBe(array_keys(config('master_data.types')))
        ->and($types['blood_group']['count'])->toBe(8)
        ->and($types['sub_region']['parent'])->toBe('region');
});

it('creates, updates and deletes an item', function () {
    Sanctum::actingAs($this->owner);

    $id = $this->postJson('/api/v1/master-data/department', ['name' => 'Finance', 'name_local' => 'অর্থ', 'code' => 'FIN'])
        ->assertCreated()
        ->assertJsonPath('data.name_local', 'অর্থ')
        ->json('data.id');

    $this->putJson("/api/v1/master-data/department/{$id}", ['name' => 'Finance & Accounts', 'code' => 'FIN', 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->deleteJson("/api/v1/master-data/department/{$id}")->assertNoContent();
    expect(MasterDataItem::where('public_id', $id)->exists())->toBeFalse();
});

it('rejects unknown types', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/master-data/spaceship')->assertNotFound();
    $this->postJson('/api/v1/master-data/spaceship', ['name' => 'x'])->assertNotFound();
});

it('requires a parent of the right type for child lists', function () {
    Sanctum::actingAs($this->owner);
    $region = masterItem($this->tenant, ['type' => 'region', 'name' => 'Dhaka']);
    $department = masterItem($this->tenant, ['type' => 'department', 'name' => 'HR']);

    $this->postJson('/api/v1/master-data/sub_region', ['name' => 'Savar'])->assertJsonValidationErrors('parent_id');
    $this->postJson('/api/v1/master-data/sub_region', ['name' => 'Savar', 'parent_id' => $department->public_id])->assertJsonValidationErrors('parent_id');
    $this->postJson('/api/v1/master-data/department', ['name' => 'X', 'parent_id' => $region->public_id])->assertJsonValidationErrors('parent_id');

    $this->postJson('/api/v1/master-data/sub_region', ['name' => 'Savar', 'parent_id' => $region->public_id])
        ->assertCreated()
        ->assertJsonPath('data.parent.name', 'Dhaka');
});

it('allows the same name under different parents but not under the same one', function () {
    Sanctum::actingAs($this->owner);
    $dhaka = masterItem($this->tenant, ['type' => 'region', 'name' => 'Dhaka']);
    $sylhet = masterItem($this->tenant, ['type' => 'region', 'name' => 'Sylhet']);

    $this->postJson('/api/v1/master-data/sub_region', ['name' => 'Sadar', 'parent_id' => $dhaka->public_id])->assertCreated();
    $this->postJson('/api/v1/master-data/sub_region', ['name' => 'Sadar', 'parent_id' => $sylhet->public_id])->assertCreated();
    $this->postJson('/api/v1/master-data/sub_region', ['name' => 'Sadar', 'parent_id' => $dhaka->public_id])->assertJsonValidationErrors('name');
});

it('rejects duplicate names and codes within a type', function () {
    Sanctum::actingAs($this->owner);
    masterItem($this->tenant, ['type' => 'rank', 'name' => 'Sergeant', 'code' => 'SGT']);

    $this->postJson('/api/v1/master-data/rank', ['name' => 'Sergeant'])->assertJsonValidationErrors('name');
    $this->postJson('/api/v1/master-data/rank', ['name' => 'Corporal', 'code' => 'SGT'])->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/master-data/designation', ['name' => 'Sergeant', 'code' => 'SGT'])->assertCreated();
});

it('blocks deleting a parent that still has children', function () {
    Sanctum::actingAs($this->owner);
    $region = masterItem($this->tenant, ['type' => 'region', 'name' => 'Dhaka']);
    masterItem($this->tenant, ['type' => 'sub_region', 'name' => 'Savar', 'parent_id' => $region->id]);

    $this->deleteJson("/api/v1/master-data/region/{$region->public_id}")->assertUnprocessable();
});

it('blocks deleting an item used by a cardholder', function () {
    Sanctum::actingAs($this->owner);
    $rank = masterItem($this->tenant, ['type' => 'rank', 'name' => 'Captain']);
    $unused = masterItem($this->tenant, ['type' => 'rank', 'name' => 'Major']);
    app(TenantContext::class)->run($this->tenant, fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id, 'rank_id' => $rank->id]));

    $this->deleteJson("/api/v1/master-data/rank/{$rank->public_id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', __('master_data.in_use'));
    $this->deleteJson("/api/v1/master-data/rank/{$unused->public_id}")->assertNoContent();
});

it('filters by search, parent and active state', function () {
    Sanctum::actingAs($this->owner);
    $dhaka = masterItem($this->tenant, ['type' => 'region', 'name' => 'Dhaka', 'code' => 'DHK']);
    $sylhet = masterItem($this->tenant, ['type' => 'region', 'name' => 'Sylhet', 'code' => 'SYL', 'is_active' => false]);
    masterItem($this->tenant, ['type' => 'sub_region', 'name' => 'Savar', 'parent_id' => $dhaka->id]);
    masterItem($this->tenant, ['type' => 'sub_region', 'name' => 'Beanibazar', 'parent_id' => $sylhet->id]);

    $this->getJson('/api/v1/master-data/region?filter[search]=dhk')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Dhaka');
    $this->getJson('/api/v1/master-data/region?filter[is_active]=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Sylhet');
    $this->getJson("/api/v1/master-data/sub_region?filter[parent]={$dhaka->public_id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Savar');
});

it('keeps organizations isolated', function () {
    [$otherTenant] = tenantWithOwner('beta', 'b@example.com');
    $foreign = masterItem($otherTenant, ['type' => 'department', 'name' => 'Secret Dept']);
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/master-data/department')->assertJsonCount(0, 'data');
    $this->putJson("/api/v1/master-data/department/{$foreign->public_id}", ['name' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/master-data/department/{$foreign->public_id}")->assertNotFound();
});

it('enforces permissions', function () {
    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));

    $this->getJson('/api/v1/master-data/department')->assertOk();
    $this->postJson('/api/v1/master-data/department', ['name' => 'X'])->assertForbidden();
});

it('adds missing defaults to existing organizations without duplicating them', function () {
    MasterDataItem::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('name', 'O-')->delete();

    $this->artisan('app:sync-master-data')->assertSuccessful();
    $this->artisan('app:sync-master-data')->assertSuccessful();

    expect(MasterDataItem::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('type', 'blood_group')->count())->toBe(8);
});

it('validates list parameters', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/master-data/department?filter[unknown]=1')->assertJsonValidationErrors('filter');
    $this->getJson('/api/v1/master-data/department?sort=password')->assertJsonValidationErrors('sort');
    $this->getJson('/api/v1/master-data/department?per_page=abc')->assertJsonValidationErrors('per_page');
});

it('returns every item without pagination for dropdowns', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/master-data/blood_group?per_page=all&sort=-name')
        ->assertOk()
        ->assertJsonMissingPath('meta')
        ->assertJsonCount(8, 'data')
        ->assertJsonPath('data.0.name', 'O-');
});
