<?php

use App\Data\Cards\ImportCardData;
use App\Models\Card;
use App\Models\Cardholder;
use App\Models\CardPrint;
use App\Services\Cardholders\CardholderImportMapper;
use App\Services\Cards\CardIssueService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP', 'validity_months' => 24]);
    $this->cards = fn (array $options = []) => ['import_cards' => 1, 'card_template' => $this->template->public_id, 'create_missing_lookups' => 1, ...$options];
    Sanctum::actingAs($this->owner);
});

/**
 * Upload rows for analysis (the import is not started).
 */
function analyzeCards(array $rows, array $options): TestResponse
{
    $handle = fopen('php://temp', 'r+');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }
    rewind($handle);
    $file = UploadedFile::fake()->createWithContent('people.csv', stream_get_contents($handle));

    return test()->post('/api/v1/cardholder-imports', ['file' => $file, ...$options], ['Accept' => 'application/json']);
}

const LEGACY_HEADERS = ['Card ID', 'Officer Name', 'Service ID', 'TS_NO', 'Rank Name', 'Blood Group', 'Birth Date', 'Comm Date', 'Branch Name', 'Issue Date', 'Last Print Date', 'Last Print Time', 'Print Reason'];

it('imports the legacy file with each person’s card and last printing', function () {
    $id = analyzeCards([
        LEGACY_HEADERS,
        ['BAF-1001', 'Rahim Uddin', 'S-1', 'T-1', 'Captain', 'A+', '1990-04-15', '2012-01-01', 'Admin', '2024-03-01', '2024-03-02', '14:05:09', 'Promotion'],
        ['EMP/24000123', 'Karim Mia', 'S-2', '', 'Captain', 'O+', '1988-01-31', '', 'Admin', '2024-05-10', '', '', ''],
    ], ($this->cards)())
        ->assertCreated()
        ->assertJsonPath('data.valid_rows', 2)
        ->assertJsonPath('data.summary.ignored', [])
        ->assertJsonPath('data.summary.cards', ['imported' => 2, 'skipped' => 0])
        ->assertJsonPath('data.summary.card_template.code', 'EMP')
        ->assertJsonPath('data.summary.preview.0.card', 'imported')
        ->json('data.id');
    expect(Card::query()->withoutGlobalScopes()->count())->toBe(0);

    $this->postJson("/api/v1/cardholder-imports/{$id}/start")->assertOk();
    $this->getJson("/api/v1/cardholder-imports/{$id}")
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.created_count', 2)
        ->assertJsonPath('data.summary.cards_result', ['imported' => 2, 'skipped' => 0]);

    $rahim = Cardholder::query()->where('employee_no', 'S-1')->sole();
    $card = Card::query()->where('cardholder_id', $rahim->id)->sole();
    expect($rahim->joining_date->toDateString())->toBe('2012-01-01')
        ->and($card->number)->toBe('BAF-1001')
        ->and($card->notes)->toBe('Promotion')
        ->and($card->issue_date->toDateString())->toBe('2024-03-01')
        ->and($card->expiry_date->toDateString())->toBe('2026-02-28')
        ->and($card->issued_by)->toBe($this->owner->id)
        ->and($card->print_count)->toBe(1)
        // 14:05:09 in Dhaka; the legacy import wrote the month where the hour belongs
        ->and($card->last_printed_at->setTimezone('Asia/Dhaka')->format('Y-m-d H:i:s'))->toBe('2024-03-02 14:05:09')
        ->and(CardPrint::query()->where('card_id', $card->id)->sole()->method)->toBe('import');

    // Not printed yet; the sequence moved past the imported number in the organization format
    $karim = Card::query()->where('number', 'EMP/24000123')->sole();
    expect($karim->print_count)->toBe(0);
    expect(app(TenantContext::class)->run($this->tenant->fresh(), fn () => DB::table('card_sequences')->where('key', 'EMP/24')->value('last_value')))->toBe(123);
});

it('lists card columns as unused unless cards are imported', function () {
    analyzeCards([['Name', 'Card no', 'Issue date'], ['A', 'X-1', '2024-01-01']], [])
        ->assertJsonPath('data.valid_rows', 1)
        ->assertJsonPath('data.summary.ignored', ['Card no', 'Issue date'])
        ->assertJsonPath('data.summary.card_columns', ['Card no', 'Issue date'])
        ->assertJsonPath('data.summary.cards', ['imported' => 0, 'skipped' => 0]);
});

it('rejects rows whose card cannot be imported, without saving the person', function () {
    $other = cardholder($this->tenant, ['full_name' => 'Taken', 'employee_no' => 'S-9']);
    app(TenantContext::class)->run($this->tenant->fresh(), fn () => app(CardIssueService::class)
        ->importCard($other, $this->template, new ImportCardData(number: 'TAKEN-1'), $this->owner));

    $response = analyzeCards([
        ['Name', 'Card no', 'Issue date', 'Last print date', 'Last print time'],
        ['A', 'TAKEN-1', '2024-01-01', '', ''],
        ['B', 'DUP-1', '2024-01-01', '', ''],
        ['C', 'DUP-1', '2024-01-01', '', ''],
        ['D', 'D-1', '31/02/2024', '', ''],
        ['E', 'E-1', '2024-01-01', '', '10:00'],
        ['F', 'F-1', '2024-01-01', '2024-01-02', '25:00'],
        ['G', '', '', '', ''],
    ], ($this->cards)())
        ->assertJsonPath('data.valid_rows', 2)
        ->assertJsonPath('data.summary.cards', ['imported' => 1, 'skipped' => 0]);

    expect(collect($response->json('data.errors'))->mapWithKeys(fn (array $error) => [$error['row'] => $error['messages']])->all())->toBe([
        2 => [__('cards.import.number_in_use', ['number' => 'TAKEN-1'])],
        4 => [__('cardholders.import.duplicate_card_in_file', ['value' => 'DUP-1'])],
        5 => [__('cardholders.import.card_invalid_date', ['field' => __('cardholders.import.card_fields.issue_date'), 'value' => '31/02/2024'])],
        6 => [__('cardholders.import.card_time_without_date')],
        7 => [__('cardholders.import.card_invalid_time', ['value' => '25:00'])],
    ]);

    $this->postJson("/api/v1/cardholder-imports/{$response->json('data.id')}/start")->assertOk();
    // B with its card, G (no card columns filled) without one
    expect(Cardholder::query()->pluck('full_name')->sort()->values()->all())->toBe(['B', 'G', 'Taken'])
        ->and(Card::query()->count())->toBe(2);
});

it('skips a card the person already has and replaces an older one when updating', function () {
    $run = function (array $row) {
        $id = analyzeCards([['Name', 'Employee no', 'Card no', 'Issue date'], $row], ($this->cards)(['update_existing' => 1]))->json('data.id');
        $this->postJson("/api/v1/cardholder-imports/{$id}/start")->assertOk();

        return $this->getJson("/api/v1/cardholder-imports/{$id}")->json('data.summary.cards_result');
    };

    expect($run(['Jane', 'E-1', 'OLD-1', '2023-01-01']))->toBe(['imported' => 1, 'skipped' => 0])
        ->and($run(['Jane', 'E-1', 'OLD-1', '2023-01-01']))->toBe(['imported' => 0, 'skipped' => 1])
        ->and($run(['Jane', 'E-1', 'OLD-2', '2024-01-01']))->toBe(['imported' => 1, 'skipped' => 0]);

    expect(Card::query()->orderBy('id')->pluck('status')->map->value->all())->toBe(['replaced', 'active']);
});

it('needs an active cardholder template and the right to issue cards', function () {
    $family = cardTemplate($this->tenant, ['code' => 'FAM', 'audience' => 'family_member']);
    analyzeCards([['Name'], ['A']], ['import_cards' => 1])->assertJsonValidationErrors('card_template');
    analyzeCards([['Name'], ['A']], ['import_cards' => 1, 'card_template' => $family->public_id])->assertJsonValidationErrors('card_template');

    $importer = tenantUser($this->tenant, 'Viewer');
    app(TenantContext::class)->run($this->tenant, fn () => $importer->givePermissionTo('cardholders.import'));
    Sanctum::actingAs($importer->fresh());
    analyzeCards([['Name'], ['A']], [])->assertCreated();
    analyzeCards([['Name'], ['A']], ($this->cards)())->assertForbidden();
});

it('offers the card columns in the template file', function () {
    $csv = $this->get('/api/v1/cardholder-imports/template')->assertOk()->getContent();

    expect($csv)->toContain('"Card no","Issue date","Expiry date","RF card no","Last print date","Last print time","Card notes"');
});

it('reads print dates and times in the usual spreadsheet shapes, in the organization time zone', function (string $date, string $time, string $expected) {
    $mapper = app(CardholderImportMapper::class);
    $columns = $mapper->mapHeaders(['Name', 'Last print date', 'Last print time'])['columns'];
    $card = $mapper->toCard(['A', $date, $time], $columns, 'Asia/Dhaka');

    expect($card['errors'])->toBe([])
        ->and($card['data']->lastPrintedAt->setTimezone('Asia/Dhaka')->format('Y-m-d H:i:s'))->toBe($expected);
})->with([
    'legacy' => ['2024-03-02', '14:05:09', '2024-03-02 14:05:09'],
    'pm' => ['02/03/2024', '2:30 PM', '2024-03-02 14:30:00'],
    'midnight am' => ['02/03/2024', '12:15 am', '2024-03-02 00:15:00'],
    'excel fraction' => ['45353', '0.5', '2024-03-02 12:00:00'],
    'date with time' => ['2024-03-02 09:10', '', '2024-03-02 09:10:00'],
    'excel date-time' => ['45353.75', '', '2024-03-02 18:00:00'],
    'no time' => ['2024-03-02', '', '2024-03-02 00:00:00'],
]);
