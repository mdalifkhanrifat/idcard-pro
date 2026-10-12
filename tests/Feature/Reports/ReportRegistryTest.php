<?php

use App\Models\CustomField;
use App\Models\FamilyMember;
use App\Models\User;
use App\Services\Reports\Definitions\CardPrintReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

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
        ->and($report['description'])->toBe('Every printing of every card: when, by whom, how and how many copies.')
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

it('lets the organization choose the columns a report shows by default, kept in its audit log', function () {
    $standard = ['holder.name', 'card.reason', 'card.number', 'note', 'printed_at', 'printed_by', 'copies'];
    $chosen = ['holder.name', 'cardholder.rank', 'cardholder.branch', 'cardholder.employee_no', 'card.number', 'cardholder.custom.house'];

    $this->putJson('/api/v1/reports/card-prints/columns', ['columns' => $chosen])->assertOk()
        ->assertJsonPath('data.default_columns', $chosen)
        ->assertJsonPath('data.standard_columns', $standard);
    $this->getJson('/api/v1/reports')->assertJsonPath('data.0.default_columns', $chosen);
    expect($this->tenant->refresh()->settings['reports'])->toBe(['card_prints' => ['columns' => $chosen]]);

    // Downloads without chosen columns use the organization's
    $this->get('/api/v1/reports/card-prints/export?format=csv')->assertOk();
    expect(Activity::query()->where('log_name', 'reports')->sole()->properties['columns'])->toBe($chosen);

    $change = Activity::query()->where('log_name', 'tenant')->latest('id')->first();
    expect($change->causer_id)->toBe($this->owner->id)
        ->and($change->attribute_changes['attributes'])->toBe(['settings.reports.card_prints.columns' => $chosen]);

    // A custom field turned off drops out of the default
    app(TenantContext::class)->run($this->tenant, fn () => CustomField::query()->where('key', 'house')->update(['is_active' => false]));
    $this->getJson('/api/v1/reports')->assertJsonPath('data.0.default_columns', array_slice($chosen, 0, 5));

    // Back to the report's own default: nothing is kept, so later improvements to it apply
    $this->putJson('/api/v1/reports/card-prints/columns', ['columns' => $standard])->assertOk()->assertJsonPath('data.default_columns', $standard);
    expect($this->tenant->refresh()->settings)->not->toHaveKey('reports');
    $this->putJson('/api/v1/reports/card-prints/columns', ['columns' => ['card.number']])->assertOk();
    $this->putJson('/api/v1/reports/card-prints/columns', ['columns' => null])->assertOk()->assertJsonPath('data.default_columns', $standard);
    expect($this->tenant->refresh()->settings)->not->toHaveKey('reports');
});

it('validates the default columns and needs settings.update', function () {
    $this->putJson('/api/v1/reports/card-prints/columns', ['columns' => []])->assertJsonValidationErrors('columns');
    $this->putJson('/api/v1/reports/card-prints/columns', [])->assertJsonValidationErrors('columns');
    $this->putJson('/api/v1/reports/card-prints/columns', ['columns' => ['card.number', 'card.number', 'cardholder.password']])->assertJsonValidationErrors(['columns.0', 'columns.2']);
    $this->putJson('/api/v1/reports/no-such-report/columns', ['columns' => ['card.number']])->assertNotFound();

    Sanctum::actingAs(tenantUser($this->tenant, 'Administrator'));
    $this->putJson('/api/v1/reports/card-prints/columns', ['columns' => ['card.number']])->assertForbidden();
});

it('describes each report\'s filters, sorts and totals so a client builds its page from them', function () {
    $report = collect($this->getJson('/api/v1/reports')->json('data'))->keyBy('key')['card_prints'];
    $filters = collect($report['filters'])->keyBy('key');

    expect($report)->toMatchArray(['sorts' => ['printed_at' => 'printed_at', 'copies' => 'copies'], 'default_sort' => '-printed_at'])
        ->and($report['totals'])->toBe([['key' => 'prints', 'label' => 'Printings'], ['key' => 'copies', 'label' => 'Copies'], ['key' => 'cards', 'label' => 'Cards']])
        ->and($filters['printed_to'])->toBe(['key' => 'printed_to', 'type' => 'date', 'label' => 'Printed on or before', 'after' => 'printed_from'])
        ->and($filters['method']['options'])->toContain(['value' => 'import', 'label' => 'Imported (printed earlier)'])
        ->and($filters['printed_by']['type'])->toBe('user')
        ->and($filters['template']['type'])->toBe('template')
        // Every master data list of a cardholder, a child list inside its parent
        ->and($filters['unit'])->toMatchArray(['type' => 'master_data', 'master_data' => 'unit', 'parent' => 'department'])
        ->and($filters['rank'])->toMatchArray(['type' => 'master_data', 'master_data' => 'rank', 'label' => 'Rank / grade']);
});

it('serves a new report from its class and a line in the registry alone, and only to whom it allows', function () {
    config(['reports.reports.biometric_prints' => BiometricPrintsReport::class]);
    $this->getJson('/api/v1/reports/no-such-report')->assertNotFound();

    $keys = fn () => collect($this->getJson('/api/v1/reports')->json('data'))->pluck('key')->all();
    expect($keys())->toBe(['card_prints', 'biometric_prints']);
    $this->getJson('/api/v1/reports/biometric-prints?columns[]=holder.name')->assertOk()->assertJsonPath('meta.totals.prints', 0);
    $this->get('/api/v1/reports/biometric-prints/export?format=csv')->assertOk();

    // A Viewer has reports.view but not what this report asks for in addition
    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    expect($keys())->toBe(['card_prints']);
    $this->getJson('/api/v1/reports/biometric-prints')->assertForbidden();
    $this->getJson('/api/v1/reports/card-prints')->assertOk();
});

/**
 * A report added in a test: the card print report under another key, for users who may see biometrics.
 */
class BiometricPrintsReport extends CardPrintReport
{
    public function key(): string
    {
        return 'biometric_prints';
    }

    public function allows(User $user): bool
    {
        return $user->can('biometrics.view');
    }
}
