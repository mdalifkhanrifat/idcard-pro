<?php

use App\Models\CardPrint;
use App\Support\Pdf\PdfOptions;
use App\Support\Pdf\PdfRenderer;
use App\Support\Pdf\PdfRenderException;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP']);
    $this->issue = fn () => $this->postJson('/api/v1/cardholders/'.cardholder($this->tenant)->public_id.'/cards', ['template' => $this->template->public_id, 'reason' => 'new'])->json('data');
    Sanctum::actingAs($this->owner);

    // The engine is replaced: it records the page it was asked to draw
    $this->engine = new class implements PdfRenderer
    {
        /** @var array<int, string> */
        public array $urls = [];

        public bool $fail = false;

        public function renderUrl(string $url, PdfOptions $options = new PdfOptions): string
        {
            if ($this->fail) {
                throw new PdfRenderException('Chromium crashed');
            }
            $this->urls[] = $url;

            return '%PDF-1.7 cards';
        }
    };
    $this->app->instance(PdfRenderer::class, $this->engine);
});

it('downloads a card as a PDF drawn from its render page, without logging a print', function () {
    $card = ($this->issue)();

    $response = $this->get("/api/v1/cards/{$card['id']}/pdf?sides=front")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="card-'.str_replace('/', '-', $card['number']).'.pdf"')
        ->and($response->getContent())->toBe('%PDF-1.7 cards')
        ->and($this->engine->urls)->toHaveCount(1)
        ->and($this->engine->urls[0])->toStartWith('http://web/render/cards?')->toContain($card['id'])->toContain('sides=front')
        ->and(CardPrint::count())->toBe(0);
});

it('lays the cards out on a paper sheet when asked, and only on known papers', function () {
    [$a, $b] = [($this->issue)(), ($this->issue)()];

    $this->postJson('/api/v1/cards/pdf', ['cards' => [$a['id'], $b['id']], 'sheet' => 'letter'])->assertOk();
    $this->get("/api/v1/cards/{$a['id']}/pdf")->assertOk();

    parse_str((string) parse_url($this->engine->urls[0], PHP_URL_QUERY), $batch);
    parse_str((string) parse_url($this->engine->urls[1], PHP_URL_QUERY), $single);
    expect($batch['sheet'])->toBe('letter')
        ->and($single['sheet'])->toBe('card');

    $this->postJson('/api/v1/cards/pdf', ['cards' => [$a['id']], 'sheet' => 'a0'])->assertJsonValidationErrors('sheet');
    expect($this->engine->urls)->toHaveCount(2);
});

it('logs the PDF as a print when asked', function () {
    $card = ($this->issue)();

    $this->get("/api/v1/cards/{$card['id']}/pdf?record=1&note=Sent+to+the+printer+room")->assertOk();

    $this->getJson("/api/v1/cards/{$card['id']}/prints")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.method', 'pdf')
        ->assertJsonPath('data.0.copies', 1)
        ->assertJsonPath('data.0.note', 'Sent to the printer room');
});

it('puts several cards in one PDF in the order given', function () {
    [$a, $b] = [($this->issue)(), ($this->issue)()];

    $response = $this->postJson('/api/v1/cards/pdf', ['cards' => [$b['id'], $a['id'], $b['id']], 'record' => true])->assertOk();

    parse_str((string) parse_url($this->engine->urls[0], PHP_URL_QUERY), $query);
    expect($query['cards'])->toBe($b['id'].','.$a['id'])
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment; filename="cards-')
        ->and(CardPrint::count())->toBe(2)
        ->and(CardPrint::pluck('batch')->unique())->toHaveCount(1);
});

it('refuses cards that cannot be printed before drawing anything', function () {
    [$a, $revoked] = [($this->issue)(), ($this->issue)()];
    $this->postJson("/api/v1/cards/{$revoked['id']}/revoke", ['note' => 'Left'])->assertOk();

    $this->getJson("/api/v1/cards/{$revoked['id']}/pdf")->assertUnprocessable()->assertJsonPath('message', fn (string $m) => str_contains($m, $revoked['number']));
    $this->postJson('/api/v1/cards/pdf', ['cards' => [$a['id'], $revoked['id']]])->assertUnprocessable();
    $this->postJson('/api/v1/cards/pdf', ['cards' => [$a['id'], '01JUNKNOWNCARD000000000000']])->assertJsonValidationErrors('cards');
    $this->postJson('/api/v1/cards/pdf', ['cards' => [$a['id']], 'sides' => 'left'])->assertJsonValidationErrors('sides');
    $this->getJson("/api/v1/cards/{$a['id']}/pdf?cards[]={$a['id']}")->assertJsonValidationErrors('cards');

    expect($this->engine->urls)->toBe([]);
});

it('reports an unavailable PDF engine and logs nothing', function () {
    $card = ($this->issue)();
    $this->engine->fail = true;

    $this->getJson("/api/v1/cards/{$card['id']}/pdf?record=1")
        ->assertStatus(503)
        ->assertJsonPath('message', __('cards.print.pdf_unavailable'));
    expect(CardPrint::count())->toBe(0);
});

it('needs the print permission and keeps cards to their organization', function () {
    $card = ($this->issue)();

    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    $this->getJson("/api/v1/cards/{$card['id']}/pdf")->assertForbidden();
    $this->postJson('/api/v1/cards/pdf', ['cards' => [$card['id']]])->assertForbidden();

    [, $stranger] = tenantWithOwner('globex', 'owner@globex.test');
    Sanctum::actingAs($stranger);
    $this->getJson("/api/v1/cards/{$card['id']}/pdf")->assertNotFound();
    $this->postJson('/api/v1/cards/pdf', ['cards' => [$card['id']]])->assertJsonValidationErrors('cards');

    expect($this->engine->urls)->toBe([]);
});
