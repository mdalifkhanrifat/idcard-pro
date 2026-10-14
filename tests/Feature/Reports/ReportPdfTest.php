<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    Storage::fake('public');
    config(['pdf.driver' => 'gotenberg', 'pdf.gotenberg.url' => 'http://gotenberg:3000']);
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka', 'logo_path' => UploadedFile::fake()->image('logo.png', 40, 20)->store('logos', 'public')]);
    $this->owner->setRelation('tenant', $this->tenant);
    $this->rank = masterItem($this->tenant, ['type' => 'rank', 'name' => 'Captain']);
    $template = cardTemplate($this->tenant, ['code' => 'EMP']);
    Sanctum::actingAs($this->owner);
    $jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => 'S-1', 'rank_id' => $this->rank->id]);
    $card = $this->postJson("/api/v1/cardholders/{$jane->public_id}/cards", ['template' => $template->public_id, 'reason' => 'new'])->json('data');
    $this->postJson("/api/v1/cards/{$card['id']}/prints", ['copies' => 7, 'note' => '<b>not bold</b>'])->assertCreated();

    // The PDF engine is faked: it "opens" the signed page the way the real one would and keeps what it got
    $this->pages = [];
    $this->engineDown = false;
    Http::fake(function (Request $request) {
        if ($this->engineDown) {
            return Http::response('Chromium failed', 503);
        }
        $fields = collect($request->data())->keyBy('name');
        $page = $this->get(str_replace('http://web', '', $fields['url']['contents']));
        $this->pages[] = ['status' => $page->status(), 'html' => $page->getContent(), 'footer' => $fields['files']['contents'] ?? null, 'margin' => $fields['marginBottom']['contents'], 'wait' => $fields['waitForExpression']['contents'] ?? null, 'url' => $fields['url']['contents']];

        return Http::response('%PDF-1.7 fake', 200);
    });
});

it('makes a document of the report with the logo, the criteria in words, totals and rows, with page numbers', function () {
    $response = $this->get("/api/v1/reports/card-prints/export?format=pdf&filter[rank]={$this->rank->public_id}&columns[]=holder.name&columns[]=cardholder.rank&columns[]=copies&columns[]=note")->assertOk();
    $page = $this->pages[0];

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toMatch('/^attachment; filename=card-prints-.*\.pdf$/')
        // The engine opened a short signed link of the app's own address, and waited for the fonts
        ->and($page['url'])->toMatch('#^http://web/api/v1/report-render/[A-Za-z0-9]{48}\?expires=\d+&signature=#')
        ->and($page['status'])->toBe(200)
        ->and($page['wait'])->toBe('window.reportReady === true')
        ->and($page['margin'])->toBe('14mm')
        ->and($page['footer'])->toContain('class="pageNumber"', 'class="totalPages"', 'Acme · Card print history')
        ->and($page['html'])->toContain('data:image/png;base64,', '>Acme<', 'Card print history', 'Rank / grade: <b>Captain</b>', 'Jane Doe', 'Captain', 'Made ', 'by Owner', '1 rows', 'Copies</span><strong>7</strong>', "font-family:'Noto Sans Bengali'")
        // Values are text, never markup
        ->and($page['html'])->toContain('&lt;b&gt;not bold&lt;/b&gt;')->not->toContain('<b>not bold</b>')
        // Legacy "#": rows numbered
        ->and($page['html'])->toContain('<th class="serial">#</th>', '<td class="serial">1</td>');
    expect(Activity::query()->where('log_name', 'reports')->sole()->properties->only(['format', 'rows'])->all())->toBe(['format' => 'pdf', 'rows' => 1]);
    // Read once: the page is gone after the engine had it
    $this->get(str_replace('http://web', '', $page['url']))->assertNotFound();
});

it('writes the document in Bangla with Bangla digits for Bangla readers', function () {
    $this->owner->update(['locale' => 'bn']);
    $this->get('/api/v1/reports/card-prints/export?format=pdf&columns[]=copies')->assertOk();

    expect($this->pages[0]['html'])->toContain('lang="bn"', 'কার্ড ছাপার ইতিহাস', 'সব সারি (কোনো ফিল্টার নেই)', '>৭<', '১টা সারি', '<td class="serial">১</td>')
        ->and($this->pages[0]['footer'])->toContain('পাতা');
});

it('opens a PDF to print, kept in the audit log as such; only PDFs open that way', function () {
    $response = $this->get('/api/v1/reports/card-prints/export?format=pdf&inline=1')->assertOk();

    expect($response->headers->get('Content-Disposition'))->toMatch('/^inline; filename="card-prints-.*\.pdf"$/')
        ->and(Activity::query()->where('log_name', 'reports')->sole()->properties['print'])->toBeTrue();
    $this->getJson('/api/v1/reports/card-prints/export?format=csv&inline=1')->assertJsonValidationErrors('inline');
});

it('keeps PDFs smaller than spreadsheets, and says so when the engine fails', function () {
    config(['reports.pdf_max_rows' => 0]);
    $this->getJson('/api/v1/reports/card-prints/export?format=pdf')->assertStatus(422)->assertJsonPath('message', 'The report has 1 rows; one download may hold at most 0. Narrow the filter (for example by date) and download in parts.');
    $this->get('/api/v1/reports/card-prints/export?format=csv')->assertOk();

    config(['reports.pdf_max_rows' => 2000]);
    $this->engineDown = true;
    $this->getJson('/api/v1/reports/card-prints/export?format=pdf')->assertStatus(503)->assertJsonPath('message', 'The PDF could not be made right now. Try again, or download CSV or Excel.');
    expect(Activity::query()->where('log_name', 'reports')->pluck('properties')->pluck('format')->all())->toBe(['csv']);
});

it('offers no PDF without a PDF engine, and the render page only behind its signature', function () {
    config(['pdf.driver' => null]);
    expect($this->getJson('/api/v1/reports')->json('data.0.formats'))->toBe(['csv', 'xlsx']);
    $this->getJson('/api/v1/reports/card-prints/export?format=pdf')->assertJsonValidationErrors('format');

    $this->get('/api/v1/report-render/'.str_repeat('a', 48))->assertForbidden();
});

it('fits the page to the number of columns, and refuses a PDF with more columns than paper can show readably', function () {
    $keys = collect($this->getJson('/api/v1/reports')->json('data'))->firstWhere('key', 'card_prints')['columns'];
    $columns = fn (int $count) => collect($keys)->take($count)->map(fn (array $column) => 'columns[]='.$column['key'])->implode('&');
    $pageOf = function (int $count) use ($columns): string {
        $this->get("/api/v1/reports/card-prints/export?format=pdf&{$columns($count)}")->assertOk();

        return (string) array_pop($this->pages)['html'];
    };

    expect($pageOf(6))->toContain('size: A4 portrait', 'font-size: 8.5pt')
        ->and($pageOf(7))->toContain('size: A4 landscape', 'font-size: 8.5pt')
        ->and($pageOf(14))->toContain('size: A4 landscape', 'font-size: 7.5pt')
        ->and($pageOf(20))->toContain('size: A3 landscape', 'font-size: 7.5pt');

    // Past the last layout: refused before any work, with what to do instead; spreadsheets take any number
    $this->getJson("/api/v1/reports/card-prints/export?format=pdf&{$columns(21)}")
        ->assertStatus(422)
        ->assertJsonPath('message', '21 columns are chosen; a PDF can show at most 20 and stay readable on paper. Choose fewer columns, or download Excel or CSV, which hold any number.');
    $this->getJson("/api/v1/reports/card-prints/export?format=pdf&inline=1&{$columns(21)}")->assertStatus(422);
    // In the reader's digits
    $this->owner->update(['locale' => 'bn']);
    expect($this->getJson("/api/v1/reports/card-prints/export?format=pdf&{$columns(21)}")->json('message'))->toStartWith('২১টা ঘর বাছাই করা; PDF কাগজে পড়ার মতো রাখতে সর্বোচ্চ ২০টা ঘর');
    config(['reports.pdf_max_rows' => 0]);
    expect($this->getJson('/api/v1/reports/card-prints/export?format=pdf')->json('message'))->toStartWith('রিপোর্টে ১টা সারি, এক ডাউনলোডে সর্বোচ্চ ০টা যায়।');
    config(['reports.pdf_max_rows' => 2000]);
    $this->owner->update(['locale' => 'en']);
    $this->get("/api/v1/reports/card-prints/export?format=csv&{$columns(40)}")->assertOk();
    expect(Activity::query()->where('log_name', 'reports')->pluck('properties')->map(fn ($properties) => $properties['format'])->all())->toBe(['pdf', 'pdf', 'pdf', 'pdf', 'csv']);

    // The limit is told to clients, from the layouts in configuration
    expect(collect($this->getJson('/api/v1/reports')->json('data'))->firstWhere('key', 'card_prints')['format_column_limits'])->toBe(['pdf' => 20]);
    config(['reports.pdf_layouts' => [['max_columns' => 30, 'paper' => 'A3', 'orientation' => 'landscape', 'font_size' => 6.5]]]);
    expect(collect($this->getJson('/api/v1/reports')->json('data'))->firstWhere('key', 'card_prints')['format_column_limits'])->toBe(['pdf' => 30])
        ->and($pageOf(25))->toContain('size: A3 landscape', 'font-size: 6.5pt');
});
