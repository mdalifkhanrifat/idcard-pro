<?php

use App\Models\CustomField;
use App\Models\FamilyMember;
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
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00', 'Asia/Dhaka'));
    Sanctum::actingAs($this->owner);
    app(TenantContext::class)->run($this->tenant, fn () => CustomField::factory()->create(['tenant_id' => $this->tenant->id, 'entity' => 'cardholder', 'is_active' => true, 'key' => 'shift', 'label' => 'Shift', 'type' => 'select', 'options' => ['Day', 'Night']]));

    $this->religion = masterItem($this->tenant, ['type' => 'religion', 'name' => 'Faith']);
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP']);
    $familyTemplate = cardTemplate($this->tenant, ['code' => 'FAM', 'audience' => 'family_member']);
    $issue = fn ($holder, array $extra = []) => $this->postJson("/api/v1/cardholders/{$holder->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new', ...$extra])->assertCreated()->json('data');
    $jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => 'E-1', 'religion_id' => $this->religion->id, 'custom_fields' => ['shift' => 'Night']]);
    $john = cardholder($this->tenant, ['full_name' => 'John Roe', 'employee_no' => 'E-2', 'custom_fields' => ['shift' => 'Day']]);
    $issue($jane, ['issue_date' => '2023-01-01', 'expiry_date' => '2024-01-01']);
    $this->janeCard = $issue($jane, ['reason' => 'renewal', 'issue_date' => '2024-03-01', 'expiry_date' => '2026-03-01']);
    $this->johnCard = $issue($john, ['issue_date' => '2025-04-20', 'expiry_date' => '2026-04-20']);
    $member = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $john->id, 'name' => 'Baby Roe', 'relation' => 'daughter']));
    $this->babyCard = $this->postJson("/api/v1/cardholders/{$john->public_id}/cards", ['template' => $familyTemplate->public_id, 'family_member' => $member->public_id, 'reason' => 'new', 'issue_date' => '2025-06-01', 'expiry_date' => '2027-06-01'])->assertCreated()->json('data');
    $this->postJson("/api/v1/cards/{$this->johnCard['id']}/prints")->assertCreated();
});

it('finds the issued cards the card list finds, for every criterion of the list', function () {
    $ids = fn (string $url) => array_column($this->getJson($url)->assertOk()->json('data'), 'id');
    $queries = [
        '', 'filter[status]=replaced', 'filter[status]=active', 'filter[validity]=expired', 'filter[validity]=valid', 'filter[validity]=expiring&filter[expiring_days]=15',
        'filter[printed]=pending', 'filter[printed]=printed', 'filter[reason]=renewal', 'filter[holder]=family', "filter[template]={$this->template->public_id}",
        'filter[issued_from]=2025-01-01', 'filter[printed_from]=2026-04-10&filter[printed_to]=2026-04-10', 'filter[search]=Roe', "filter[religion]={$this->religion->public_id}",
        'filter[custom][shift]=Day', 'sort=expiry_date', 'sort=-number',
    ];
    foreach ($queries as $query) {
        expect($ids("/api/v1/reports/cards?{$query}"))->toBe($ids("/api/v1/cards?{$query}"), $query);
    }
    expect($ids('/api/v1/reports/cards?filter[custom][shift]=Day'))->toBe([$this->babyCard['id'], $this->johnCard['id']])
        ->and($ids("/api/v1/reports/cards?filter[religion]={$this->religion->public_id}"))->toHaveCount(2);
});

it('shows the card list\'s columns by default, with totals over every page', function () {
    $report = collect($this->getJson('/api/v1/reports')->json('data'))->keyBy('key')['cards'];
    expect($report['default_columns'])->toBe(['holder.name', 'cardholder.employee_no', 'card.number', 'card.reason', 'card.status', 'card.template', 'card.issue_date', 'card.expiry_date', 'card.print_count', 'card.last_printed_at'])
        ->and(array_column($report['filters'], 'key'))->toContain('validity', 'printed', 'created_from', 'religion', 'custom.shift');

    $this->getJson('/api/v1/reports/cards?filter[status]=active&sort=number&columns[]=holder.name&columns[]=card.status&columns[]=card.print_count')
        ->assertJsonPath('meta.totals', ['cards' => 3, 'cardholders' => 2, 'family_cards' => 1])
        ->assertJsonPath('data.0.values', ['holder.name' => 'Jane Doe', 'card.status' => 'Active', 'card.print_count' => 0]);
});

it('filters print history and expired cards by the cardholder\'s custom fields and every master data list too', function () {
    $this->getJson('/api/v1/reports/card-prints?filter[custom][shift]=Day')->assertJsonCount(1, 'data')->assertJsonPath('data.0.card.id', $this->johnCard['id']);
    $this->getJson('/api/v1/reports/card-prints?filter[custom][shift]=Night')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/reports/expired-cards?filter[custom][shift]=Night')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->janeCard['id']);
    $this->getJson("/api/v1/reports/expired-cards?filter[religion]={$this->religion->public_id}")->assertJsonCount(1, 'data');
    // The card list itself takes them as well
    $this->getJson('/api/v1/cards?filter[custom][shift]=Night')->assertJsonCount(2, 'data');
});

it('needs the cards.view permission and downloads what the list finds', function () {
    $this->get('/api/v1/reports/cards/export?format=csv&filter[holder]=family&columns[]=holder.name&columns[]=family.relation')
        ->assertOk()
        ->assertStreamedContent("\u{FEFF}Name,Relation\n\"Baby Roe\",Daughter\n");

    $role = app(TenantContext::class)->run($this->tenant, fn () => Role::create(['name' => 'Report reader', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id]));
    $role->givePermissionTo(['reports.view']);
    Sanctum::actingAs(tenantUser($this->tenant, 'Report reader'));
    $this->getJson('/api/v1/reports/cards')->assertForbidden();
});
