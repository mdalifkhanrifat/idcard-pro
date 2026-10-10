<?php

use App\Data\Cards\ImportCardData;
use App\Models\Card;
use App\Models\CardPrint;
use App\Models\FamilyMember;
use App\Services\Cards\CardIssueService;
use App\Support\Pdf\PdfOptions;
use App\Support\Pdf\PdfRenderer;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    Storage::fake(config('biometrics.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP']);
    $this->holder = cardholder($this->tenant, ['full_name' => 'Rahim Uddin']);
    Sanctum::actingAs($this->owner);
    /** Biometrics: one required thumb, needed before the given moments. */
    $this->require = function (array $moments, array $other = []): void {
        $this->post('/api/v1/settings', ['name' => 'Acme', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233', 'biometrics' => [
            'enabled' => '1', 'required_before' => $moments, ...$other,
            'modalities' => ['fingerprint' => ['mode' => 'required', 'count' => 1, 'positions' => ['right_thumb'], 'substitutes' => '0', 'min_quality' => 0, 'max_attempts' => 3, 'drivers' => ['upload']]],
        ]], ['Accept' => 'application/json'])->assertOk();
        $this->owner->unsetRelation('tenant');
    };
    $this->issue = fn (array $extra = [], $holder = null) => $this->postJson('/api/v1/cardholders/'.($holder ?? $this->holder)->public_id.'/cards', ['template' => $this->template->public_id, 'reason' => 'new', ...$extra]);
    $this->capture = function ($holder = null, ?FamilyMember $member = null): void {
        ob_start();
        imagepng(imagecreatetruecolor(20, 20));
        $this->postJson('/api/v1/cardholders/'.($holder ?? $this->holder)->public_id.'/biometrics', [
            'modality' => 'fingerprint', 'position' => 'right_thumb', 'driver' => 'upload', 'family_member' => $member?->public_id,
            'image_base64' => base64_encode((string) ob_get_clean()), 'image_format' => 'png',
        ])->assertCreated();
    };
    $this->overrides = fn () => DB::table('activity_log')->where('event', 'requirements_overridden')->get();
    $this->operator = function () {
        $operator = tenantUser($this->tenant, 'Operator');
        Sanctum::actingAs($operator);

        return $operator;
    };
});

it('stops issuing until the biometrics the organization requires are complete, and says what is missing', function () {
    ($this->require)(['issue']);

    ($this->issue)()->assertUnprocessable()
        ->assertJsonPath('requirements.0.code', 'biometrics')
        ->assertJsonPath('requirements.0.details.status', 'incomplete')
        ->assertJsonPath('requirements.0.details.cardholder', $this->holder->public_id)
        ->assertJsonPath('can_override', true)
        ->assertJsonPath('message', 'The card cannot be issued yet. Rahim Uddin has not completed the biometrics this organization requires.');
    expect(Card::count())->toBe(0);

    ($this->capture)();
    ($this->issue)()->assertCreated();
});

it('holds nothing up at moments the organization did not choose, or for capture that is optional', function () {
    ($this->require)(['print']);
    $card = ($this->issue)()->assertCreated()->json('data');

    // Printing is held up for the same person
    expect($card['print_requirements'][0]['code'])->toBe('biometrics');

    ($this->require)([]);
    $this->getJson("/api/v1/cards/{$card['id']}")->assertOk()->assertJsonPath('data.print_requirements', []);
});

it('lets those who may override go ahead with a reason, kept in the card\'s audit log', function () {
    ($this->require)(['issue']);

    // A reason is needed
    ($this->issue)(['override_reason' => '  '])->assertUnprocessable()->assertJsonPath('can_override', true);
    $card = ($this->issue)(['override_reason' => 'Scanner broken, urgent travel'])->assertCreated()->json('data');

    $entry = ($this->overrides)()->sole();
    expect($entry->subject_id)->toBe(Card::query()->where('public_id', $card['id'])->value('id'))
        ->and(json_decode($entry->properties, true))->toBe(['moment' => 'issue', 'reason' => 'Scanner broken, urgent travel', 'requirements' => ['biometrics']]);

    // Without cards.override_requirements a reason changes nothing
    ($this->operator)();
    ($this->issue)(['override_reason' => 'Please'], cardholder($this->tenant))->assertUnprocessable()->assertJsonPath('can_override', false);
});

it('stops printing and logging a batch when any card\'s holder is missing something, all or none', function () {
    $ready = cardholder($this->tenant, ['full_name' => 'Karim Mia']);
    $open = ($this->issue)()->json('data');
    $done = ($this->issue)([], $ready)->json('data');
    ($this->require)(['print']);
    ($this->capture)($ready);

    $this->postJson('/api/v1/cards/prints', ['cards' => [$open['id'], $done['id']]])->assertUnprocessable()
        ->assertJsonCount(1, 'requirements')
        ->assertJsonPath('requirements.0.card', $open['number']);
    expect(CardPrint::count())->toBe(0);

    $this->postJson("/api/v1/cards/{$done['id']}/prints")->assertCreated();
    $this->postJson('/api/v1/cards/prints', ['cards' => [$open['id'], $done['id']], 'override_reason' => 'Approved by HR'])->assertCreated();
    // Only the card that was missing something is marked as overridden
    expect(($this->overrides)()->pluck('subject_id')->all())->toBe([Card::query()->where('public_id', $open['id'])->value('id')])
        ->and(CardPrint::count())->toBe(3);
});

it('makes no PDF of a card whose holder is missing something, unless overridden', function () {
    $engine = new class implements PdfRenderer
    {
        public int $calls = 0;

        public function renderUrl(string $url, PdfOptions $options = new PdfOptions): string
        {
            $this->calls++;

            return '%PDF-1.7 cards';
        }
    };
    $this->app->instance(PdfRenderer::class, $engine);
    $card = ($this->issue)()->json('data');
    ($this->require)(['print']);

    $this->getJson("/api/v1/cards/{$card['id']}/pdf")->assertUnprocessable()->assertJsonPath('requirements.0.code', 'biometrics');
    expect($engine->calls)->toBe(0);

    $this->get("/api/v1/cards/{$card['id']}/pdf?override_reason=Court+order")->assertOk();
    expect($engine->calls)->toBe(1)->and(($this->overrides)()->count())->toBe(1);
});

it('checks a family member\'s own biometrics for their card', function () {
    ($this->require)(['issue'], ['family_members' => '1']);
    $member = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id, 'name' => 'Salma Begum']));
    ($this->capture)();

    $family = cardTemplate($this->tenant, ['code' => 'FAM', 'audience' => 'family_member']);
    ($this->issue)(['family_member' => $member->public_id, 'template' => $family->public_id])->assertUnprocessable()
        ->assertJsonPath('requirements.0.details.family_member', $member->public_id)
        ->assertJsonPath('message', 'The card cannot be issued yet. Salma Begum has not completed the biometrics this organization requires.');

    ($this->capture)(null, $member);
    ($this->issue)(['family_member' => $member->public_id, 'template' => $family->public_id])->assertCreated();
});

it('tells screens and connected systems beforehand whether a card can be issued or printed', function () {
    ($this->require)(['issue']);
    $url = "/api/v1/cardholders/{$this->holder->public_id}/card-requirements";

    $this->getJson("{$url}?moment=issue")->assertOk()
        ->assertJsonPath('data.ready', false)->assertJsonPath('data.problems.0.code', 'biometrics')->assertJsonPath('data.can_override', true);
    $this->getJson("{$url}?moment=print")->assertOk()->assertJsonPath('data.ready', true)->assertJsonPath('data.problems', []);
    $this->getJson("{$url}?moment=lunch")->assertJsonValidationErrors('moment');

    ($this->operator)();
    $this->getJson("{$url}?moment=issue")->assertOk()->assertJsonPath('data.can_override', false);
});

it('never holds up cards imported from an earlier system, which were already handed out', function () {
    ($this->require)(['issue', 'print']);

    $card = app(TenantContext::class)->run($this->tenant, fn () => app(CardIssueService::class)->importCard(
        $this->holder, $this->template, new ImportCardData(number: 'EMP/24000001', issueDate: CarbonImmutable::parse('2024-01-01'), lastPrintedAt: CarbonImmutable::parse('2024-01-02 10:00')), $this->owner,
    ));

    expect($card)->not->toBeNull()->and($card->print_count)->toBe(1);
});
