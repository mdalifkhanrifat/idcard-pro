<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->owner->setRelation('tenant', $this->tenant);
    $rank = masterItem($this->tenant, ['type' => 'rank', 'name' => 'Captain']);
    $template = cardTemplate($this->tenant, ['code' => 'EMP']);
    Sanctum::actingAs($this->owner);

    $jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => '+8801', 'rank_id' => $rank->id]);
    $this->card = $this->postJson("/api/v1/cardholders/{$jane->public_id}/cards", ['template' => $template->public_id, 'reason' => 'new', 'issue_date' => '2026-01-05'])->json('data');
    // A note a spreadsheet would run as a formula if written carelessly
    $this->postJson("/api/v1/cards/{$this->card['id']}/prints", ['copies' => 2, 'note' => '=HYPERLINK("http://x")'])->assertCreated();

    $this->columns = 'columns[]=holder.name&columns[]=cardholder.rank&columns[]=cardholder.employee_no&columns[]=card.issue_date&columns[]=copies&columns[]=note&columns[]=printed_at';
    // The file a download response sends (deleted only once actually sent)
    $this->fileOf = function (TestResponse $response): string {
        $path = $response->baseResponse->getFile()->getPathname();
        $contents = (string) file_get_contents($path);
        unlink($path);

        return $contents;
    };
});

it('downloads the report as an Excel workbook with real dates and numbers and text kept as text', function () {
    $response = $this->get("/api/v1/reports/card-prints/export?format=xlsx&{$this->columns}")->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))->toMatch('/attachment; filename=card-prints-\d{4}-\d{2}-\d{2}-\d{6}\.xlsx/');

    $file = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
    file_put_contents($file, ($this->fileOf)($response));
    $reader = new Reader;
    $reader->open($file);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        expect($sheet->getName())->toBe('Card print history');
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();
    unlink($file);

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(['Name', 'Rank / grade', 'Employee no', 'Issue date', 'Copies', 'Remarks', 'Printed at'])
        ->and(array_slice($rows[1], 0, 3))->toBe(['Jane Doe', 'Captain', '+8801'])
        ->and($rows[1][3])->toBeInstanceOf(DateTimeImmutable::class)
        ->and($rows[1][3]->format('Y-m-d'))->toBe('2026-01-05')
        ->and($rows[1][4])->toBe(2)
        ->and($rows[1][5])->toBe('=HYPERLINK("http://x")')
        ->and($rows[1][6])->toBeInstanceOf(DateTimeImmutable::class);
});

it('downloads the report as CSV in UTF-8 with formulas defused, in the reader\'s language', function () {
    $this->owner->update(['locale' => 'bn']);
    $response = $this->get("/api/v1/reports/card-prints/export?format=csv&{$this->columns}")->assertOk();
    $csv = ($this->fileOf)($response);
    $lines = array_map(str_getcsv(...), explode("\n", trim(substr($csv, 3))));

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
        ->and($lines[0])->toBe(['নাম', 'র‍্যাংক / গ্রেড', 'কর্মী নম্বর', 'ইস্যুর তারিখ', 'কপি', 'মন্তব্য', 'ছাপার সময়'])
        ->and(array_slice($lines[1], 0, 6))->toBe(['Jane Doe', 'Captain', "'+8801", '2026-01-05', '2', "'=HYPERLINK(\"http://x\")"])
        ->and($lines[1][6])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/');
});

it('keeps every download in the audit log with the report, format, columns, filter and rows', function () {
    $this->get('/api/v1/reports/card-prints/export?format=csv&filter[search]=jane&filter[printed_from]=2026-01-01')->assertOk();

    $activity = Activity::query()->where('log_name', 'reports')->sole();
    expect($activity->event)->toBe('exported')
        ->and($activity->causer_id)->toBe($this->owner->id)
        ->and($activity->properties->all())->toBe([
            'report' => 'card_prints',
            'format' => 'csv',
            'rows' => 1,
            // No columns chosen: the default (legacy) ones
            'columns' => ['holder.name', 'card.reason', 'card.number', 'note', 'printed_at', 'printed_by', 'copies'],
            'filter' => ['search' => 'jane', 'printed_from' => '2026-01-01'],
        ]);
    $this->getJson('/api/v1/audit/activities?filter[log_name]=reports')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.event', 'exported');
});

it('refuses a download larger than the limit, and unknown formats', function () {
    config(['reports.max_export_rows' => 0]);
    $this->getJson('/api/v1/reports/card-prints/export?format=xlsx')
        ->assertStatus(422)
        ->assertJsonPath('message', 'The report has 1 rows; one download may hold at most 0. Narrow the filter (for example by date) and download in parts.');
    $this->getJson('/api/v1/reports/card-prints/export?format=pdfx')->assertJsonValidationErrors('format');
    $this->getJson('/api/v1/reports/card-prints/export')->assertJsonValidationErrors('format');

    expect(Activity::query()->where('log_name', 'reports')->count())->toBe(0);
});

it('lets only users with reports.export download, and offers the formats to them only', function () {
    $formats = fn () => $this->getJson('/api/v1/reports')->json('data.0');
    expect($formats())->toMatchArray(['formats' => ['csv', 'xlsx'], 'max_export_rows' => 20000]);

    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    expect($formats())->toMatchArray(['formats' => [], 'max_export_rows' => null]);
    $this->getJson('/api/v1/reports/card-prints/export?format=csv')->assertForbidden();
});
