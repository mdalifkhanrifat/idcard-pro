<?php

use App\Models\BiometricCapture;
use App\Models\FamilyMember;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/** A real PNG of the given size. */
function biometricPng(int $width = 40, int $height = 50): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

beforeEach(function () {
    Storage::fake(config('biometrics.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->holder = cardholder($this->tenant);
    $this->url = "/api/v1/cardholders/{$this->holder->public_id}/biometrics";
    /** Turn biometrics on with these modality settings (merged over a required two-thumb fingerprint setting). */
    $this->enable = function (array $modalities = [], array $other = []): void {
        $this->tenant->update(['settings' => [...($this->tenant->settings ?? []), 'biometrics' => [
            'enabled' => true,
            'modalities' => [
                'fingerprint' => ['mode' => 'required', 'count' => 2, 'positions' => ['right_thumb', 'left_thumb'], 'substitutes' => false, 'min_quality' => 50, 'max_attempts' => 3, 'drivers' => ['secugen', 'upload', 'simulator']],
                ...$modalities,
            ],
            ...$other,
        ]]]);
        // The signed-in user object is reused between requests in a test, with its organization loaded
        $this->actor->unsetRelation('tenant');
    };
    $this->finger = fn (array $overrides = []) => [
        'modality' => 'fingerprint', 'position' => 'right_thumb', 'driver' => 'secugen',
        'image_base64' => 'data:image/png;base64,'.base64_encode(biometricPng()), 'image_format' => 'png',
        'template_base64' => base64_encode('FMR'.str_repeat("\x01", 40)), 'template_format' => 'iso-19794-2',
        'quality' => 80, 'attempts' => 1, 'dpi' => 500, 'device' => ['vendor' => 'SecuGen', 'model' => 'Hamster Pro 20', 'serial' => 'H58-1'],
        ...$overrides,
    ];
    $this->actor = tenantUser($this->tenant, 'Operator');
    Sanctum::actingAs($this->actor);
});

it('captures nothing while the organization has biometrics off', function () {
    $this->postJson($this->url, ($this->finger)())->assertUnprocessable()->assertJsonPath('message', __('biometrics.capture.disabled'));
    $this->getJson($this->url)->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.status.enabled', false)->assertJsonPath('meta.status.complete', true);
});

it('keeps a scanner capture encrypted and shows the image only through a short-lived link', function () {
    ($this->enable)();
    $response = $this->postJson($this->url, ($this->finger)())->assertCreated()
        ->assertJsonPath('data.position', 'right_thumb')
        ->assertJsonPath('data.position_code', 1)
        ->assertJsonPath('data.image_width', 40)
        ->assertJsonPath('data.image_height', 50)
        ->assertJsonPath('data.has_template', true)
        ->assertJsonPath('data.template_format', 'iso-19794-2')
        ->assertJsonPath('data.quality', 80)
        ->assertJsonPath('data.low_quality', false)
        ->assertJsonPath('data.device.model', 'Hamster Pro 20')
        ->assertJsonPath('meta.status.complete', false)
        ->assertJsonPath('meta.status.modalities.fingerprint.counted', 1)
        ->assertJsonPath('meta.status.modalities.fingerprint.next', ['left_thumb']);
    expect($response->json('data'))->not->toHaveKeys(['template', 'image_path', 'template_path', 'disk']);

    $capture = BiometricCapture::query()->firstOrFail();
    $disk = Storage::disk(config('biometrics.disk'));
    expect($disk->get($capture->image_path))->not->toContain('PNG')->and($disk->get($capture->template_path))->not->toContain('FMR');

    $image = $this->get($response->json('data.image_url'))->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($image->getContent())->toBe(biometricPng())
        ->and($image->headers->get('Cache-Control'))->toContain('no-store');
    $this->get("/api/v1/biometric-images/{$capture->public_id}")->assertForbidden();
    $this->travel(config('biometrics.url_ttl') + 1)->minutes();
    $this->get($response->json('data.image_url'))->assertForbidden();
});

it('accepts an uploaded image file without a quality', function () {
    ($this->enable)();
    $file = UploadedFile::fake()->createWithContent('thumb.png', biometricPng(30, 30));

    $this->post($this->url, ['modality' => 'fingerprint', 'position' => 'left_thumb', 'driver' => 'upload', 'image' => $file, 'image_format' => 'png'], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.quality', null)->assertJsonPath('data.has_template', false)->assertJsonPath('data.image_width', 30);
});

it('takes only the positions, drivers and formats the organization allows', function () {
    ($this->enable)(['face' => ['mode' => 'optional', 'count' => 1, 'positions' => ['face'], 'drivers' => ['webcam']]]);

    $this->postJson($this->url, ($this->finger)(['position' => 'right_index']))->assertJsonValidationErrors('position');
    // A driver of this installation the organization does not allow
    $this->postJson($this->url, ($this->finger)(['driver' => 'bridge']))->assertJsonValidationErrors('driver');
    $this->postJson($this->url, ($this->finger)(['image_base64' => base64_encode('not a png at all'), 'template_base64' => null]))->assertJsonValidationErrors('image');
    $this->postJson($this->url, ($this->finger)(['image_base64' => '***']))->assertJsonValidationErrors('image_base64');
    $this->postJson($this->url, ($this->finger)(['template_format' => 'vendor-x']))->assertJsonValidationErrors('template_format');
    // A face has no template and is not WSQ
    $face = ['modality' => 'face', 'position' => 'face', 'driver' => 'webcam', 'image_base64' => base64_encode(biometricPng()), 'image_format' => 'png'];
    $this->postJson($this->url, [...$face, 'template_base64' => base64_encode('x'), 'template_format' => 'iso-19794-2'])->assertJsonValidationErrors('template');
    $this->postJson($this->url, [...$face, 'image_format' => 'wsq'])->assertJsonValidationErrors('image_format');
    $this->postJson($this->url, [...$face, 'modality' => 'iris', 'position' => 'right_iris'])->assertJsonValidationErrors('modality');
    $this->postJson($this->url, $face)->assertCreated();

    // With stand-ins any finger may be captured
    ($this->enable)(['fingerprint' => ['mode' => 'required', 'count' => 2, 'positions' => ['right_thumb', 'left_thumb'], 'substitutes' => true, 'drivers' => ['secugen']]]);
    $this->postJson($this->url, ($this->finger)(['position' => 'right_index']))->assertCreated();
});

it('asks for another try below the minimum quality, then lets the operator keep the best one', function () {
    ($this->enable)();

    $this->postJson($this->url, ($this->finger)(['quality' => 30, 'attempts' => 1]))
        ->assertJsonValidationErrors(['quality' => __('biometrics.capture.low_quality_retry', ['quality' => 30, 'min' => 50, 'left' => 2])]);
    $this->postJson($this->url, ($this->finger)(['quality' => 35, 'attempts' => 3]))
        ->assertJsonValidationErrors(['quality' => __('biometrics.capture.low_quality_confirm', ['quality' => 35, 'min' => 50, 'tries' => 3])]);
    $this->postJson($this->url, ($this->finger)(['quality' => 35, 'attempts' => 3, 'accept_low_quality' => true]))
        ->assertCreated()->assertJsonPath('data.low_quality', true)->assertJsonPath('data.attempts', 3);
    expect(Storage::disk(config('biometrics.disk'))->allFiles())->toHaveCount(2);
});

it('replaces a position\'s capture and keeps only the record of the old one, not its data', function () {
    ($this->enable)();
    $this->postJson($this->url, ($this->finger)())->assertCreated();
    $old = BiometricCapture::query()->firstOrFail();
    $this->postJson($this->url, ($this->finger)(['quality' => 95]))->assertCreated();

    $old->refresh();
    expect($old->replaced_at)->not->toBeNull()->and($old->image_path)->toBeNull()->and($old->template_path)->toBeNull()
        ->and(Storage::disk(config('biometrics.disk'))->allFiles())->toHaveCount(2);
    $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quality', 95);
});

it('lets a person without fingers be complete through the reasons recorded, never blocking for what cannot be captured', function () {
    ($this->enable)();
    $unavailable = fn (string $position, string $reason, ?string $note = null) => $this->postJson("{$this->url}/unavailable", ['modality' => 'fingerprint', 'position' => $position, 'reason' => $reason, 'note' => $note]);

    $unavailable('right_thumb', 'other')->assertJsonValidationErrors('note');
    $unavailable('right_thumb', 'lunch')->assertJsonValidationErrors('reason');
    $unavailable('right_thumb', 'missing')->assertCreated()->assertJsonPath('data.status', 'unavailable')->assertJsonPath('data.image_url', null)
        ->assertJsonPath('meta.status.complete', false);
    $this->postJson($this->url, ($this->finger)(['position' => 'left_thumb']))->assertCreated()
        ->assertJsonPath('meta.status.complete', true)
        ->assertJsonPath('meta.status.modalities.fingerprint.with_exceptions', true)
        ->assertJsonPath('meta.status.modalities.fingerprint.unavailable', ['right_thumb']);
});

it('fills a missing preferred finger with a stand-in when the organization allows it', function () {
    ($this->enable)(['fingerprint' => ['mode' => 'required', 'count' => 2, 'positions' => ['right_thumb', 'left_thumb'], 'substitutes' => true, 'drivers' => ['secugen']]]);
    $this->postJson("{$this->url}/unavailable", ['modality' => 'fingerprint', 'position' => 'right_thumb', 'reason' => 'injured'])->assertCreated()
        ->assertJsonPath('meta.status.modalities.fingerprint.next', ['left_thumb', 'right_index']);
    $this->postJson($this->url, ($this->finger)(['position' => 'left_thumb']))->assertCreated()->assertJsonPath('meta.status.complete', false);

    $this->postJson($this->url, ($this->finger)(['position' => 'right_index']))->assertCreated()
        ->assertJsonPath('meta.status.complete', true)
        ->assertJsonPath('meta.status.modalities.fingerprint.with_exceptions', false);
});

it('does not let optional kinds block, and captures family members only when the organization says so', function () {
    ($this->enable)(['fingerprint' => ['mode' => 'optional', 'count' => 1, 'positions' => ['right_thumb'], 'drivers' => ['secugen']], 'iris' => ['mode' => 'required', 'count' => 1, 'positions' => ['left_iris'], 'drivers' => ['bridge']]]);
    $member = FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id]);
    $iris = ['modality' => 'iris', 'position' => 'left_iris', 'driver' => 'bridge', 'image_base64' => base64_encode(biometricPng()), 'image_format' => 'png', 'quality' => 70];

    $this->getJson($this->url)->assertJsonPath('meta.status.complete', false)->assertJsonPath('meta.status.modalities.fingerprint.required', false);
    $this->postJson($this->url, $iris)->assertCreated()->assertJsonPath('meta.status.complete', true);

    $this->postJson($this->url, [...$iris, 'family_member' => $member->public_id])->assertUnprocessable()->assertJsonPath('message', __('biometrics.capture.not_for_family'));
    $this->getJson("{$this->url}?family_member={$member->public_id}")->assertJsonPath('meta.status.applies', false)->assertJsonPath('meta.status.complete', true);

    ($this->enable)(['fingerprint' => ['mode' => 'optional', 'count' => 1, 'positions' => ['right_thumb'], 'drivers' => ['secugen']], 'iris' => ['mode' => 'required', 'count' => 1, 'positions' => ['left_iris'], 'drivers' => ['bridge']]], ['family_members' => true]);
    $this->postJson($this->url, [...$iris, 'family_member' => $member->public_id])->assertCreated();
    $this->getJson("{$this->url}?family_member={$member->public_id}")->assertJsonCount(1, 'data')->assertJsonPath('meta.status.complete', true);
    $this->getJson($this->url)->assertJsonCount(1, 'data');
});

it('lets only permitted people see, capture and delete biometrics', function () {
    ($this->enable)();
    $id = $this->postJson($this->url, ($this->finger)())->assertCreated()->json('data.id');
    $other = cardholder($this->tenant);

    $this->deleteJson("{$this->url}/{$id}")->assertForbidden();
    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    $this->getJson($this->url)->assertForbidden();
    $this->postJson($this->url, ($this->finger)())->assertForbidden();

    Sanctum::actingAs(tenantUser($this->tenant, 'Administrator'));
    $this->deleteJson("/api/v1/cardholders/{$other->public_id}/biometrics/{$id}")->assertNotFound();
    $this->deleteJson("{$this->url}/{$id}")->assertNoContent();
    expect(Storage::disk(config('biometrics.disk'))->allFiles())->toBe([])->and(BiometricCapture::query()->count())->toBe(0);
});

it('never takes made-up captures in production', function () {
    config(['biometrics.simulator' => false]);
    ($this->enable)();

    $this->postJson($this->url, ($this->finger)(['driver' => 'simulator']))->assertJsonValidationErrors('driver');
});
