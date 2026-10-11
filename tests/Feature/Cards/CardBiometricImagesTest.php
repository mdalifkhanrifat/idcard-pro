<?php

use App\Data\Cards\ImportCardData;
use App\Enums\TemplateAudience;
use App\Models\BiometricCapture;
use App\Models\Card;
use App\Models\CardBiometricImage;
use App\Models\FamilyMember;
use App\Services\Biometrics\BiometricVault;
use App\Services\Biometrics\CardBiometricImageService;
use App\Services\Cards\CardIssueService;
use App\Services\Cards\CardRenderService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
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

    /** Fingerprints printed on cards; the given changes on top. */
    $this->settings = function (array $other = [], array $fingerprint = []): void {
        $this->post('/api/v1/settings', ['name' => 'Acme', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233', 'biometrics' => [
            'enabled' => '1', 'print_on_card' => '1', 'print_source' => 'issue', ...$other,
            'modalities' => ['fingerprint' => ['mode' => 'optional', 'count' => 2, 'positions' => ['right_thumb', 'left_thumb'], 'substitutes' => '1', 'min_quality' => 0, 'max_attempts' => 3, 'drivers' => ['upload'], ...$fingerprint]],
        ]], ['Accept' => 'application/json'])->assertOk();
        $this->owner->unsetRelation('tenant');
        $this->holder->unsetRelation('tenant');
    };
    /** The template's layout: its name field and the given biometric elements. */
    $this->design = function (array $elements, $template = null): void {
        $template ??= $this->template;
        $this->putJson("/api/v1/card-templates/{$template->public_id}/elements", ['elements' => [
            ['side' => 'front', 'type' => 'field', 'field' => 'full_name', 'x' => 5, 'y' => 5, 'width' => 40, 'height' => 6],
            ...array_map(fn (array $element): array => ['side' => 'front', 'type' => 'biometric', 'x' => 60, 'y' => 30, 'width' => 18, 'height' => 20, ...$element], $elements),
        ]])->assertOk();
    };
    /** Capture a position from a small image of one colour; returns the image bytes. */
    $this->capture = function (string $position, int $shade = 0, string $format = 'png', ?FamilyMember $member = null): string {
        if ($format === 'wsq') {
            $bytes = "\xff\xa0".str_repeat("\x01", 30);
        } else {
            $image = imagecreatetruecolor(20, 20);
            imagefill($image, 0, 0, imagecolorallocate($image, $shade, $shade, $shade));
            ob_start();
            imagepng($image);
            $bytes = (string) ob_get_clean();
        }
        $this->postJson("/api/v1/cardholders/{$this->holder->public_id}/biometrics", [
            'modality' => 'fingerprint', 'position' => $position, 'driver' => 'upload', 'family_member' => $member?->public_id,
            'image_base64' => base64_encode($bytes), 'image_format' => $format,
        ])->assertCreated();

        return $bytes;
    };
    $this->issue = fn (array $extra = []) => $this->postJson("/api/v1/cardholders/{$this->holder->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new', ...$extra]);
    $this->kept = fn (): array => CardBiometricImage::query()->withoutGlobalScopes()->orderBy('field')->get()->keyBy('field')->all();
    $this->read = fn (CardBiometricImage $image): string => app(BiometricVault::class)->get((string) $image->disk, (string) $image->path);
});

it('keeps the card its own copy of the image captured at issue, which a later capture does not change', function () {
    ($this->settings)();
    ($this->design)([['field' => 'biometric.fingerprint.auto'], ['field' => 'biometric.fingerprint.left_thumb', 'x' => 40]]);
    $thumb = ($this->capture)('right_thumb', 10);

    ($this->issue)()->assertCreated();

    $kept = ($this->kept)();
    expect($kept)->toHaveKeys(['biometric.fingerprint.auto', 'biometric.fingerprint.left_thumb'])
        ->and($kept['biometric.fingerprint.auto']->position)->toBe('right_thumb')
        ->and($kept['biometric.fingerprint.auto']->image_format)->toBe('png')
        ->and(($this->read)($kept['biometric.fingerprint.auto']))->toBe($thumb)
        // Nothing printable there at issue: the card prints its fallback, never a later capture
        ->and($kept['biometric.fingerprint.left_thumb']->path)->toBeNull()
        ->and($kept['biometric.fingerprint.left_thumb']->position)->toBeNull();

    // A new capture removes the old capture's data (privacy), not the card's copy
    ($this->capture)('right_thumb', 200);
    expect(($this->read)($kept['biometric.fingerprint.auto']->fresh()))->toBe($thumb)
        ->and(BiometricCapture::query()->withoutGlobalScopes()->whereNotNull('replaced_at')->value('image_path'))->toBeNull();
});

it('keeps no copy while the organization prints the latest capture, or for a design without biometric images', function () {
    ($this->settings)(['print_source' => 'print']);
    ($this->design)([['field' => 'biometric.fingerprint.auto']]);
    ($this->capture)('right_thumb');
    ($this->issue)()->assertCreated();

    ($this->settings)();
    ($this->design)([]);
    ($this->issue)(['reason' => 'renewal'])->assertCreated();

    expect(CardBiometricImage::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Storage::disk(config('biometrics.disk'))->allFiles())->toHaveCount(1);
});

it('prints for auto the first captured in the order of preference, a stand-in only when allowed, and never WSQ', function () {
    ($this->settings)();
    ($this->design)([['field' => 'biometric.fingerprint.auto']]);
    ($this->capture)('right_thumb', 0, 'wsq');
    ($this->capture)('left_index', 50);
    $service = app(CardBiometricImageService::class);
    $holder = fn () => $this->holder->fresh();

    // The preferred right thumb is WSQ (a browser cannot draw it) and the left thumb is missing: the index stands in
    expect($service->latest($holder(), null, 'biometric.fingerprint.auto')?->position)->toBe('left_index')
        ->and($service->latest($holder(), null, 'biometric.fingerprint.right_thumb'))->toBeNull();

    ($this->capture)('left_thumb', 90);
    expect($service->latest($holder(), null, 'biometric.fingerprint.auto')?->position)->toBe('left_thumb');

    // Without stand-ins only the preferred positions count
    ($this->settings)([], ['substitutes' => '0', 'count' => 1, 'positions' => ['right_thumb']]);
    expect($service->latest($holder(), null, 'biometric.fingerprint.auto'))->toBeNull()
        ->and($service->latest($holder(), null, 'biometric.fingerprint.left_index')?->position)->toBe('left_index');

    // Printing on cards turned off: nothing at all
    ($this->settings)(['print_on_card' => '0']);
    expect($service->latest($holder(), null, 'biometric.fingerprint.left_index'))->toBeNull();
});

it('ignores hidden biometric elements, which are never printed', function () {
    ($this->settings)();
    ($this->design)([['field' => 'biometric.fingerprint.auto', 'style' => ['hidden' => true]]]);
    ($this->capture)('right_thumb');

    ($this->issue)()->assertCreated();

    expect(CardBiometricImage::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('prints a family member their own fingerprint, and none while family members are not captured', function () {
    $familyTemplate = cardTemplate($this->tenant, ['code' => 'FAM', 'audience' => TemplateAudience::FamilyMember]);
    $member = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id]));
    ($this->settings)(['family_members' => '1']);
    ($this->design)([['field' => 'biometric.fingerprint.auto']], $familyTemplate);
    ($this->capture)('right_thumb', 10);
    $own = ($this->capture)('left_thumb', 120, 'png', $member);
    $service = app(CardBiometricImageService::class);

    $this->postJson("/api/v1/cardholders/{$this->holder->public_id}/cards", ['template' => $familyTemplate->public_id, 'family_member' => $member->public_id, 'reason' => 'new'])->assertCreated();

    expect(($this->read)(($this->kept)()['biometric.fingerprint.auto']))->toBe($own);

    ($this->settings)(['family_members' => '0']);
    expect($service->latest($this->holder->fresh(), $member, 'biometric.fingerprint.auto'))->toBeNull();
});

it('removes the copies again when issuing fails', function () {
    ($this->settings)();
    ($this->design)([['field' => 'biometric.fingerprint.auto']]);
    ($this->capture)('right_thumb');
    ($this->issue)()->assertCreated();
    $card = Card::query()->withoutGlobalScopes()->sole();
    $files = Storage::disk(config('biometrics.disk'))->allFiles();

    // Keeping copies twice for one card breaks the one-row-per-field rule after the copy is made
    expect(fn () => DB::transaction(fn () => app(TenantContext::class)->run($this->tenant, fn () => app(CardBiometricImageService::class)->keepForCard($card, $this->holder->fresh(), null))))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(Storage::disk(config('biometrics.disk'))->allFiles())->toBe($files);
});

it('keeps no copy for cards imported from an earlier system, which print the latest capture', function () {
    ($this->settings)();
    ($this->design)([['field' => 'biometric.fingerprint.auto']]);
    ($this->capture)('right_thumb');

    $card = app(TenantContext::class)->run($this->tenant, fn () => app(CardIssueService::class)->importCard(
        $this->holder->fresh(), $this->template->fresh(), new ImportCardData(number: 'EMP/24000001', issueDate: CarbonImmutable::parse('2024-01-01')), $this->owner,
    ));

    expect($card)->not->toBeNull()->and(CardBiometricImage::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('still issues the card when a captured image cannot be read, printing it as if nothing was captured', function () {
    ($this->settings)();
    ($this->design)([['field' => 'biometric.fingerprint.auto']]);
    ($this->capture)('right_thumb');
    Storage::disk(config('biometrics.disk'))->delete(BiometricCapture::query()->withoutGlobalScopes()->value('image_path'));

    ($this->issue)()->assertCreated();

    $kept = ($this->kept)()['biometric.fingerprint.auto'];
    expect($kept->path)->toBeNull()->and($kept->biometric_capture_id)->toBeNull();
});

it('prints the card its copy kept at issue, through a short-lived link that is never cached', function () {
    ($this->settings)();
    ($this->design)([['field' => 'biometric.fingerprint.auto'], ['field' => 'biometric.fingerprint.left_thumb', 'x' => 40]]);
    $thumb = ($this->capture)('right_thumb', 10);
    $card = ($this->issue)()->assertCreated()->json('data');
    ($this->capture)('right_thumb', 200);
    ($this->capture)('left_thumb', 99);

    $images = $this->getJson("/api/v1/cards/{$card['id']}")->assertOk()->json('meta.values.images.biometrics');

    // The copy, not the newer captures; nothing at the left thumb then, so its fallback, not today's capture
    expect($images['biometric.fingerprint.auto'])->toStartWith('/api/v1/card-biometric-images/')
        ->and($images['biometric.fingerprint.left_thumb'])->toBeNull();
    $this->app['auth']->forgetGuards();
    $response = $this->get($images['biometric.fingerprint.auto'])->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($response->getContent())->toBe($thumb);

    // A changed or unsigned link is refused
    $this->get($images['biometric.fingerprint.auto'].'0')->assertForbidden();
    $this->get(strtok($images['biometric.fingerprint.auto'], '?'))->assertForbidden();
});

it('prints the latest capture when the organization chooses so, also for cards issued before with copies', function () {
    ($this->settings)();
    ($this->design)([['field' => 'biometric.fingerprint.auto']]);
    ($this->capture)('right_thumb', 10);
    $card = ($this->issue)()->assertCreated()->json('data');
    $newer = ($this->capture)('right_thumb', 200);

    ($this->settings)(['print_source' => 'print']);
    $url = $this->getJson("/api/v1/cards/{$card['id']}")->json('meta.values.images.biometrics')['biometric.fingerprint.auto'];

    expect($url)->toStartWith('/api/v1/biometric-images/')
        ->and($this->get($url)->assertOk()->getContent())->toBe($newer);
});

it('keeps printing the latest capture on cards issued while it was chosen, after switching to the image kept at issue', function () {
    ($this->settings)(['print_source' => 'print']);
    ($this->design)([['field' => 'biometric.fingerprint.auto']]);
    $card = ($this->issue)()->assertCreated()->json('data');
    $captured = ($this->capture)('left_index', 70);

    ($this->settings)();
    $url = $this->getJson("/api/v1/cards/{$card['id']}")->json('meta.values.images.biometrics')['biometric.fingerprint.auto'];

    expect($this->get($url)->assertOk()->getContent())->toBe($captured);
});

it('prints no biometric image once the organization stops printing them, not even a kept copy', function () {
    ($this->settings)();
    ($this->design)([['field' => 'biometric.fingerprint.auto']]);
    ($this->capture)('right_thumb');
    $card = ($this->issue)()->assertCreated()->json('data');

    ($this->settings)(['print_on_card' => '0']);

    expect($this->getJson("/api/v1/cards/{$card['id']}")->json('meta.values.images.biometrics'))->toBe(['biometric.fingerprint.auto' => null]);
});

it('draws the same images in the PDF engine and previews what a design would print, but never shows them on the public verification page', function () {
    ($this->settings)();
    ($this->design)([['field' => 'biometric.fingerprint.auto']]);
    ($this->capture)('right_thumb');
    $card = ($this->issue)()->assertCreated()->json('data');

    $link = app(TenantContext::class)->run($this->tenant, fn () => app(CardRenderService::class)->link([$card['id']]));
    $this->app['auth']->forgetGuards();
    $render = $this->getJson('/api/v1/card-render?'.parse_url($link, PHP_URL_QUERY))->assertOk()->json('data.cards.0.values.images.biometrics');
    expect($render['biometric.fingerprint.auto'])->toStartWith('/api/v1/card-biometric-images/');

    Sanctum::actingAs($this->owner);
    $preview = $this->getJson("/api/v1/card-templates/{$this->template->public_id}/preview?cardholder={$this->holder->public_id}")->assertOk()->json('data.images.biometrics');
    expect($preview['biometric.fingerprint.auto'])->toStartWith('/api/v1/biometric-images/');

    $this->post('/api/v1/settings', ['name' => 'Acme', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233', 'verification' => ['enabled' => '1', 'fields' => ['photo', 'full_name']]], ['Accept' => 'application/json'])->assertOk();
    $code = Card::query()->withoutGlobalScopes()->value('verify_code');
    $public = $this->getJson("/api/v1/verify/{$code}")->assertOk()->getContent();
    expect($public)->not->toContain('biometric');
});
