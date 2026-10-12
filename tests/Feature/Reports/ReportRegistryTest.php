<?php

use App\Models\CustomField;
use App\Models\FamilyMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->owner->setRelation('tenant', $this->tenant);
    $this->customField = fn (array $attributes) => app(TenantContext::class)->run($this->tenant, fn () => CustomField::factory()->create([
        'tenant_id' => $this->tenant->id, 'entity' => 'cardholder', 'type' => 'text', ...$attributes,
    ]));
    ($this->customField)(['key' => 'house', 'label' => 'House', 'label_local' => 'হাউস']);
    ($this->customField)(['key' => 'old_code', 'label' => 'Old code', 'is_active' => false]);
    Sanctum::actingAs($this->owner);
});

it('lists every report with its fixed, cardholder and custom field columns and the legacy default columns', function () {
    $report = collect($this->getJson('/api/v1/reports')->assertOk()->json('data'))->keyBy('key')['card_prints'];
    $columns = collect($report['columns'])->keyBy('key');

    expect($report['name'])->toBe('Card print history')
        ->and($report['default_columns'])->toBe(['holder.name', 'card.reason', 'card.number', 'note', 'printed_at', 'printed_by', 'copies'])
        ->and($columns['printed_at'])->toBe(['key' => 'printed_at', 'label' => 'Printed at', 'type' => 'datetime', 'group' => 'print'])
        ->and($columns['card.issue_date']['type'])->toBe('date')
        ->and($columns['copies']['type'])->toBe('number')
        // Every field a card can print, master data included, comes from the designer's field registry
        ->and($columns['cardholder.rank'])->toMatchArray(['label' => 'Rank / grade', 'group' => 'cardholder'])
        ->and($columns->keys())->toContain('cardholder.employee_no', 'cardholder.branch', 'cardholder.national_id')
        // Active custom fields only
        ->and($columns['cardholder.custom.house'])->toMatchArray(['label' => 'House', 'group' => 'custom'])
        ->and($columns->has('cardholder.custom.old_code'))->toBeFalse();
});

it('labels the columns in the reader\'s language, custom fields by their local label', function () {
    $this->owner->update(['locale' => 'bn']);
    $report = collect($this->getJson('/api/v1/reports')->json('data'))->keyBy('key')['card_prints'];
    $columns = collect($report['columns'])->keyBy('key');

    expect($report['name'])->toBe('কার্ড ছাপার ইতিহাস')
        ->and($columns['card.number']['label'])->toBe('কার্ড নম্বর')
        ->and($columns['cardholder.rank']['label'])->toBe('র‍্যাংক / গ্রেড')
        ->and($columns['cardholder.custom.house']['label'])->toBe('হাউস');
});

it('gives each report row the values of the chosen columns as the report shows them', function () {
    $rank = masterItem($this->tenant, ['type' => 'rank', 'name' => 'Captain']);
    $template = cardTemplate($this->tenant, ['code' => 'EMP', 'name' => 'Staff card']);
    $familyTemplate = cardTemplate($this->tenant, ['code' => 'FAM', 'audience' => 'family_member']);
    $jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'rank_id' => $rank->id, 'custom_fields' => ['house' => 'Blue']]);
    $member = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $jane->id, 'name' => 'Baby Doe', 'relation' => 'daughter']));
    $card = $this->postJson("/api/v1/cardholders/{$jane->public_id}/cards", ['template' => $template->public_id, 'reason' => 'new', 'issue_date' => '2026-01-05'])->json('data');
    $babyCard = $this->postJson("/api/v1/cardholders/{$jane->public_id}/cards", ['template' => $familyTemplate->public_id, 'family_member' => $member->public_id, 'reason' => 'renewal'])->json('data');
    $this->postJson("/api/v1/cards/{$card['id']}/prints", ['note' => 'First'])->assertCreated();
    $this->postJson("/api/v1/cards/{$babyCard['id']}/prints", ['method' => 'pdf'])->assertCreated();

    $columns = 'columns[]=holder.name&columns[]=family.relation&columns[]=cardholder.rank&columns[]=cardholder.custom.house&columns[]=card.issue_date&columns[]=card.reason&columns[]=method&columns[]=printed_at';
    $rows = $this->getJson("/api/v1/reports/card-prints?{$columns}")->assertOk()->json('data');

    expect(array_keys($rows[0]['values']))->toBe(['holder.name', 'family.relation', 'cardholder.rank', 'cardholder.custom.house', 'card.issue_date', 'card.reason', 'method', 'printed_at'])
        // The family card: its holder is the family member, the cardholder's fields still come with it
        ->and($rows[0]['values'])->toMatchArray(['holder.name' => 'Baby Doe', 'family.relation' => 'Daughter', 'cardholder.rank' => 'Captain', 'card.reason' => 'Renewal', 'method' => 'PDF'])
        ->and($rows[1]['values'])->toMatchArray(['holder.name' => 'Jane Doe', 'family.relation' => null, 'cardholder.custom.house' => 'Blue', 'card.issue_date' => '2026-01-05', 'method' => 'Browser'])
        // A moment in the organization's time zone
        ->and($rows[1]['values']['printed_at'])->toEndWith('+06:00');

    // Without columns the rows stay as they were; unknown and inactive columns are refused
    expect($this->getJson('/api/v1/reports/card-prints')->json('data.0'))->not->toHaveKey('values');
    $this->getJson('/api/v1/reports/card-prints?columns[]=cardholder.password&columns[]=cardholder.custom.old_code')->assertJsonValidationErrors(['columns.0', 'columns.1']);
});

it('shows the registry only to users who may see reports', function () {
    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));
    $this->getJson('/api/v1/reports')->assertForbidden();
    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('data.0.key', 'card_prints');
});
