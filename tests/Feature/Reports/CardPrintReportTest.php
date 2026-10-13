<?php

use App\Models\Card;
use App\Models\CardPrint;
use App\Models\FamilyMember;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->owner->setRelation('tenant', $this->tenant);
    $this->rank = masterItem($this->tenant, ['type' => 'rank', 'name' => 'Captain', 'code' => 'CPT']);
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP', 'name' => 'Staff card']);
    $this->familyTemplate = cardTemplate($this->tenant, ['code' => 'FAM', 'audience' => 'family_member']);
    Sanctum::actingAs($this->owner);

    $this->jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => 'E-1', 'rank_id' => $this->rank->id]);
    $john = cardholder($this->tenant, ['full_name' => 'John Roe', 'employee_no' => 'E-2']);
    $member = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $john->id, 'name' => 'Baby Roe']));
    $this->janeCard = $this->postJson("/api/v1/cardholders/{$this->jane->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new'])->json('data');
    $this->johnCard = $this->postJson("/api/v1/cardholders/{$john->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new'])->json('data');
    $this->babyCard = $this->postJson("/api/v1/cardholders/{$john->public_id}/cards", ['template' => $this->familyTemplate->public_id, 'family_member' => $member->public_id, 'reason' => 'new'])->json('data');

    // Printed at a given moment of the organization's day (Asia/Dhaka, UTC+6)
    $this->printAt = function (array $card, string $localTime, array $data = []) {
        $id = $this->postJson("/api/v1/cards/{$card['id']}/prints", $data)->assertCreated()->json('data.id');
        CardPrint::query()->withoutGlobalScopes()->where('public_id', $id)->update(['printed_at' => CarbonImmutable::parse($localTime, 'Asia/Dhaka')->utc()]);

        return $id;
    };
});

it('lists every printing with its card, holder and master data, newest first, with totals', function () {
    ($this->printAt)($this->janeCard, '2026-03-01 10:00');
    ($this->printAt)($this->janeCard, '2026-03-02 10:00', ['method' => 'pdf', 'copies' => 3, 'note' => 'Printer jam']);
    ($this->printAt)($this->babyCard, '2026-03-03 10:00');

    $response = $this->getJson('/api/v1/reports/card-prints')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.totals', ['prints' => 3, 'copies' => 5, 'cards' => 2])
        ->assertJsonPath('data.0.family_member.name', 'Baby Roe')
        ->assertJsonPath('data.0.cardholder.full_name', 'John Roe')
        ->assertJsonPath('data.0.cardholder.lookups.rank', null)
        ->assertJsonPath('data.1.copies', 3)
        ->assertJsonPath('data.1.method', 'pdf')
        ->assertJsonPath('data.1.note', 'Printer jam')
        ->assertJsonPath('data.1.printed_by.name', 'Owner')
        ->assertJsonPath('data.1.card.number', $this->janeCard['number'])
        ->assertJsonPath('data.1.card.template.code', 'EMP')
        ->assertJsonPath('data.1.cardholder.employee_no', 'E-1')
        ->assertJsonPath('data.1.cardholder.lookups.rank', ['id' => $this->rank->public_id, 'name' => 'Captain', 'code' => 'CPT']);

    // Every master data type is present, set or not: a stable shape for any client
    expect(array_keys($response->json('data.1.cardholder.lookups')))->toContain('rank', 'branch', 'department', 'appointment');
    $this->getJson('/api/v1/reports/card-prints?sort=copies&per_page=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.copies', 1)->assertJsonPath('meta.last_page', 3);
});

it('counts the whole last day of a date range in the organization\'s time zone (legacy report left it out)', function () {
    ($this->printAt)($this->janeCard, '2026-03-30 23:59');
    ($this->printAt)($this->janeCard, '2026-03-31 00:00');
    ($this->printAt)($this->janeCard, '2026-03-31 23:59');
    ($this->printAt)($this->janeCard, '2026-04-01 00:00');

    $this->getJson('/api/v1/reports/card-prints?filter[printed_from]=2026-03-31&filter[printed_to]=2026-03-31')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.totals.prints', 2);
    $this->getJson('/api/v1/reports/card-prints?filter[printed_from]=2026-03-31')->assertJsonCount(3, 'data');
    $this->getJson('/api/v1/reports/card-prints?filter[printed_to]=2026-03-31')->assertJsonCount(3, 'data');
});

it('filters by holder, card, card type, template, method, who printed, batch and any master data', function () {
    ($this->printAt)($this->janeCard, '2026-03-01 10:00');
    ($this->printAt)($this->johnCard, '2026-03-01 11:00', ['method' => 'pdf']);
    ($this->printAt)($this->babyCard, '2026-03-01 12:00');
    $batch = $this->postJson('/api/v1/cards/prints', ['cards' => [$this->janeCard['id'], $this->johnCard['id']]])->assertCreated()->json('data.0.batch');
    $operator = tenantUser($this->tenant, 'Operator');

    $this->getJson('/api/v1/reports/card-prints?filter[search]=jane')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/reports/card-prints?filter[search]=E-2')->assertJsonCount(3, 'data');
    $this->getJson('/api/v1/reports/card-prints?filter[search]=Baby')->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/reports/card-prints?filter[search]={$this->janeCard['number']}")->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/reports/card-prints?filter[template]={$this->familyTemplate->public_id}")->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/reports/card-prints?filter[holder]=family')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/reports/card-prints?filter[holder]=cardholder')->assertJsonCount(4, 'data');
    $this->getJson('/api/v1/reports/card-prints?filter[method]=pdf')->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/reports/card-prints?filter[batch]={$batch}")->assertJsonCount(2, 'data')->assertJsonPath('meta.totals.cards', 2);
    $this->getJson("/api/v1/reports/card-prints?filter[printed_by]={$this->owner->public_id}")->assertJsonCount(5, 'data');
    $this->getJson("/api/v1/reports/card-prints?filter[printed_by]={$operator->public_id}")->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/reports/card-prints?filter[rank]={$this->rank->public_id}")->assertJsonCount(2, 'data')->assertJsonPath('data.0.cardholder.lookups.rank.name', 'Captain');

    // Legacy "Card Type" search: why the card was issued
    Card::query()->withoutGlobalScopes()->where('public_id', $this->babyCard['id'])->update(['reason' => 'lost']);
    $this->getJson('/api/v1/reports/card-prints?filter[reason]=lost')->assertJsonCount(1, 'data')->assertJsonPath('data.0.card.reason', 'lost');
    $this->getJson('/api/v1/reports/card-prints?filter[reason]=new')->assertJsonCount(4, 'data');
});

it('validates the filter', function () {
    $this->getJson('/api/v1/reports/card-prints?filter[printed_from]=2026-04-02&filter[printed_to]=2026-04-01&filter[method]=fax&filter[holder]=x&filter[reason]=stolen&filter[colour]=red&sort=number&per_page=500')
        ->assertJsonValidationErrors(['filter', 'filter.printed_to', 'filter.method', 'filter.holder', 'filter.reason', 'sort', 'per_page']);
    $this->getJson('/api/v1/reports/card-prints?filter[printed_from]=01/04/2026')->assertJsonValidationErrors('filter.printed_from');
    $this->getJson('/api/v1/reports/card-prints?filter[method]=import')->assertOk();
});

it('needs the reports.view permission and shows only the organization\'s own printings', function () {
    ($this->printAt)($this->janeCard, '2026-03-01 10:00');
    [$other] = tenantWithOwner('globex', 'owner@globex.test');
    app(TenantContext::class)->run($other, fn () => CardPrint::factory()->create(['tenant_id' => $other->id, 'card_id' => app(TenantContext::class)->run($other, fn () => Card::factory()->create(['tenant_id' => $other->id]))->id]));

    $this->getJson('/api/v1/reports/card-prints')->assertJsonCount(1, 'data')->assertJsonPath('meta.totals.prints', 1);

    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));
    $this->getJson('/api/v1/reports/card-prints')->assertForbidden();
    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    $this->getJson('/api/v1/reports/card-prints')->assertOk()->assertJsonCount(1, 'data');
});
