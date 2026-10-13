<?php

use App\Models\Signatory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->owner->setRelation('tenant', $this->tenant);
    $this->travelTo(CarbonImmutable::parse('2026-04-10 01:00', 'Asia/Dhaka'));
    Sanctum::actingAs($this->owner);
    $this->authority = masterItem($this->tenant, ['type' => 'authority_type', 'name' => 'Head office']);
    $signatory = fn (array $attributes) => app(TenantContext::class)->run($this->tenant, fn () => Signatory::factory()->create(['tenant_id' => $this->tenant->id, ...$attributes]));
    // Signs from 10 April in Dhaka (still 9 April in UTC at this hour)
    $this->newHead = $signatory(['name' => 'Nadia New', 'designation' => 'Director', 'authority_type_id' => $this->authority->id, 'effective_from' => '2026-04-10']);
    $this->oldHead = $signatory(['name' => 'Omar Old', 'designation' => 'Director', 'authority_type_id' => $this->authority->id, 'effective_to' => '2026-04-09']);
    $this->retired = $signatory(['name' => 'Rita Retired', 'designation' => 'HR Manager', 'is_active' => false]);

    $this->cardType = masterItem($this->tenant, ['type' => 'card_type', 'name' => 'Staff ID']);
    $this->staff = cardTemplate($this->tenant, ['name' => 'Staff card', 'code' => 'EMP', 'card_type_id' => $this->cardType->id]);
    $this->family = cardTemplate($this->tenant, ['name' => 'Family card', 'code' => 'FAM', 'audience' => 'family_member', 'width' => 100, 'height' => '70.50', 'is_active' => false]);
    $holder = cardholder($this->tenant, ['full_name' => 'Jane Doe']);
    $this->postJson("/api/v1/cardholders/{$holder->public_id}/cards", ['template' => $this->staff->public_id, 'reason' => 'new'])->assertCreated();
});

it('lists the signatories the signatory page finds, and who signs today in the organization\'s days', function () {
    $ids = fn (string $url) => collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    foreach (['', 'filter[search]=director', 'filter[is_active]=0', 'filter[is_active]=1'] as $query) {
        expect($ids("/api/v1/reports/signatories?{$query}"))->toBe($ids("/api/v1/signatories?{$query}"), $query);
    }
    expect($ids("/api/v1/reports/signatories?filter[authority_type]={$this->authority->public_id}"))->toHaveCount(2);

    $values = collect($this->getJson('/api/v1/reports/signatories?columns[]=signatory.name&columns[]=signatory.authority_type&columns[]=signatory.in_effect&columns[]=signatory.status')->json('data'))->pluck('values')->keyBy(fn (array $row) => $row['signatory.name']);
    expect($values['Nadia New'])->toMatchArray(['signatory.authority_type' => 'Head office', 'signatory.in_effect' => 'Yes', 'signatory.status' => 'Active'])
        ->and($values['Omar Old']['signatory.in_effect'])->toBe('No')
        ->and($values['Rita Retired'])->toMatchArray(['signatory.in_effect' => 'No', 'signatory.status' => 'Inactive']);
    $this->getJson('/api/v1/reports/signatories')->assertJsonPath('meta.totals', ['signatories' => 3, 'active' => 2]);
});

it('lists the card templates the template page finds, with their size and cards issued', function () {
    $ids = fn (string $url) => collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    foreach (['', 'filter[search]=fam', "filter[card_type]={$this->cardType->public_id}", 'filter[audience]=family_member', 'filter[is_active]=1'] as $query) {
        expect($ids("/api/v1/reports/card-templates?{$query}"))->toBe($ids("/api/v1/card-templates?{$query}"), $query);
    }

    $this->getJson('/api/v1/reports/card-templates?sort=code&columns[]=template.name&columns[]=template.card_type&columns[]=template.audience&columns[]=template.size&columns[]=template.cards&columns[]=template.status')
        ->assertJsonPath('meta.totals', ['templates' => 2, 'active' => 1])
        ->assertJsonPath('data.0.values', ['template.name' => 'Staff card', 'template.card_type' => 'Staff ID', 'template.audience' => 'Cardholders', 'template.size' => '85.6 × 54 mm', 'template.cards' => 1, 'template.status' => 'Active'])
        ->assertJsonPath('data.1.values', ['template.name' => 'Family card', 'template.card_type' => null, 'template.audience' => 'Family members', 'template.size' => '100 × 70.5 mm', 'template.cards' => 0, 'template.status' => 'Inactive']);
});

it('needs the signatories.view and templates.view permissions', function () {
    $role = app(TenantContext::class)->run($this->tenant, fn () => Role::create(['name' => 'Report reader', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id]));
    $role->givePermissionTo(['reports.view']);
    Sanctum::actingAs(tenantUser($this->tenant, 'Report reader'));
    $this->getJson('/api/v1/reports/signatories')->assertForbidden();
    $this->getJson('/api/v1/reports/card-templates')->assertForbidden();
});
