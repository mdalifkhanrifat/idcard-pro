<?php

use App\Models\MasterDataItem;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    Sanctum::actingAs($this->owner);
    $this->hq = masterItem($this->tenant, ['type' => 'department', 'name' => 'Headquarters', 'code' => 'HQ']);
    $this->ops = masterItem($this->tenant, ['type' => 'department', 'name' => 'Operations', 'code' => 'OPS']);
    $this->hqUnit = masterItem($this->tenant, ['type' => 'unit', 'name' => 'HQ unit', 'parent_id' => $this->hq->id]);
    $this->opsUnit = masterItem($this->tenant, ['type' => 'unit', 'name' => 'Field unit', 'parent_id' => $this->ops->id, 'is_active' => false]);
    $this->rank = masterItem($this->tenant, ['type' => 'rank', 'name' => 'Captain']);
});

it('lists one master data list as its page finds it, or every list, the lists from configuration', function () {
    $ids = fn (string $url) => array_column($this->getJson($url)->assertOk()->json('data'), 'id');

    foreach (['', 'filter[search]=hq', 'filter[is_active]=0', 'sort=name', "filter[parent]={$this->ops->public_id}"] as $query) {
        $report = str_replace("filter[parent]={$this->ops->public_id}", "filter[department]={$this->ops->public_id}", $query);
        expect($ids("/api/v1/reports/master-data?filter[type]=unit&{$report}"))->toBe($ids("/api/v1/master-data/unit?{$query}"), $query);
    }
    // Every list (a new organization starts with some lists filled), grouped by list
    $this->getJson('/api/v1/reports/master-data?per_page=100')->assertJsonPath('meta.total', MasterDataItem::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());

    $this->getJson('/api/v1/reports/master-data?filter[type]=unit&sort=name&columns[]=master_data.type&columns[]=master_data.name&columns[]=master_data.parent&columns[]=master_data.status')
        ->assertJsonPath('meta.totals', ['items' => 2, 'active' => 1])
        ->assertJsonPath('data.0.values', ['master_data.type' => 'Units', 'master_data.name' => 'Field unit', 'master_data.parent' => 'Operations', 'master_data.status' => 'Inactive']);

    $filters = collect(collect($this->getJson('/api/v1/reports')->json('data'))->keyBy('key')['master_data']['filters'])->keyBy('key');
    expect(array_column($filters['type']['options'], 'value'))->toBe(array_keys(config('master_data.types')))
        ->and($filters['department'])->toMatchArray(['type' => 'master_data', 'master_data' => 'department', 'when' => ['type' => 'unit']])
        ->and($filters['region'])->toMatchArray(['when' => ['type' => 'sub_region']]);
});

it('needs the master_data.view permission', function () {
    $role = app(TenantContext::class)->run($this->tenant, fn () => Role::create(['name' => 'Report reader', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id]));
    $role->givePermissionTo(['reports.view']);
    Sanctum::actingAs(tenantUser($this->tenant, 'Report reader'));
    $this->getJson('/api/v1/reports/master-data')->assertForbidden();
});
