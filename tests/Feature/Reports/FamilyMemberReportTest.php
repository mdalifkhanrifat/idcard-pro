<?php

use App\Models\CustomField;
use App\Models\FamilyMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    Sanctum::actingAs($this->owner);
    $inTenant = fn (Closure $make) => app(TenantContext::class)->run($this->tenant, $make);
    $inTenant(fn () => CustomField::factory()->create(['tenant_id' => $this->tenant->id, 'entity' => 'cardholder', 'is_active' => true, 'key' => 'shift', 'label' => 'Shift', 'type' => 'select', 'options' => ['Day', 'Night']]));
    $this->religion = masterItem($this->tenant, ['type' => 'religion', 'name' => 'Faith']);
    $jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => 'E-1', 'religion_id' => $this->religion->id, 'custom_fields' => ['shift' => 'Night']]);
    $john = cardholder($this->tenant, ['full_name' => 'John Roe', 'employee_no' => 'E-2']);
    $member = fn ($holder, array $attributes) => $inTenant(fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $holder->id, ...$attributes]));
    $this->janesFather = $member($jane, ['name' => 'Abdul Doe', 'relation' => 'father', 'gender' => 'male', 'date_of_birth' => '1950-02-03', 'phone' => '0171']);
    $this->janesMother = $member($jane, ['name' => 'Amina Doe', 'relation' => 'mother', 'gender' => 'female']);
    $this->johnsFather = $member($john, ['name' => 'Karim Roe', 'relation' => 'father', 'gender' => 'male']);
    $this->johnsSon = $member($john, ['name' => 'Baby Roe', 'relation' => 'son', 'gender' => 'male']);
    // The son's own card, not printed yet
    $template = cardTemplate($this->tenant, ['code' => 'FAM', 'audience' => 'family_member']);
    $this->sonCard = $this->postJson("/api/v1/cardholders/{$john->public_id}/cards", ['template' => $template->public_id, 'family_member' => $this->johnsSon->public_id, 'reason' => 'new'])->assertCreated()->json('data');
});

it('lists every family member of every cardholder, as the legacy family list did, by relation and more', function () {
    $ids = fn (string $query) => array_column($this->getJson("/api/v1/reports/family-members?{$query}")->assertOk()->json('data'), 'id');

    expect($ids('filter[relation]=father&sort=name'))->toBe([$this->janesFather->public_id, $this->johnsFather->public_id])
        ->and($ids('filter[gender]=female'))->toBe([$this->janesMother->public_id])
        ->and($ids('filter[search]=Roe&sort=name'))->toBe([$this->johnsSon->public_id, $this->johnsFather->public_id])
        ->and($ids('filter[search]=E-1&sort=name'))->toBe([$this->janesFather->public_id, $this->janesMother->public_id])
        ->and($ids("filter[religion]={$this->religion->public_id}"))->toHaveCount(2)
        ->and($ids('filter[custom][shift]=Night'))->toHaveCount(2);

    $this->getJson('/api/v1/reports/family-members?filter[relation]=father&sort=name&columns[]=cardholder.full_name&columns[]=family.name&columns[]=family.relation&columns[]=family.date_of_birth&columns[]=family.phone')
        ->assertJsonPath('meta.totals', ['members' => 2, 'cardholders' => 2])
        ->assertJsonPath('data.0.values', ['cardholder.full_name' => 'Jane Doe', 'family.name' => 'Abdul Doe', 'family.relation' => 'Father', 'family.date_of_birth' => '1950-02-03', 'family.phone' => '0171'])
        ->assertJsonPath('data.0.cardholder.full_name', 'Jane Doe');
});

it('shows a member\'s own card to those who may see cards, and names its column groups', function () {
    $report = collect($this->getJson('/api/v1/reports')->json('data'))->keyBy('key')['family_members'];
    expect($report['groups'])->toMatchArray(['family' => 'Family member', 'cardholder' => 'Cardholder'])
        ->and(array_column($report['columns'], 'key'))->toContain('family.card_number', 'family.card_state', 'cardholder.custom.shift');

    $this->getJson('/api/v1/reports/family-members?filter[search]=Baby&columns[]=family.card_number&columns[]=family.card_state')
        ->assertJsonPath('data.0.values', ['family.card_number' => $this->sonCard['number'], 'family.card_state' => 'Not printed']);

    $role = app(TenantContext::class)->run($this->tenant, fn () => Role::create(['name' => 'Report reader', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id]));
    $role->givePermissionTo(['reports.view', 'cardholders.view']);
    Sanctum::actingAs(tenantUser($this->tenant, 'Report reader'));
    $report = collect($this->getJson('/api/v1/reports')->json('data'))->keyBy('key')['family_members'];
    expect(array_column($report['columns'], 'key'))->not->toContain('family.card_number', 'family.card_state');

    $role->revokePermissionTo('cardholders.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->getJson('/api/v1/reports/family-members')->assertForbidden();
});

it('downloads the members it finds', function () {
    $this->get('/api/v1/reports/family-members/export?format=csv&filter[relation]=mother&columns[]=cardholder.full_name&columns[]=family.name&columns[]=family.relation')
        ->assertOk()
        ->assertStreamedContent("\u{FEFF}\"Full name\",\"Member name\",Relation\n\"Jane Doe\",\"Amina Doe\",Mother\n");
});
