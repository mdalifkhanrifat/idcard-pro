<?php

use App\Models\CardTemplate;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->template = app(TenantContext::class)->run($this->tenant, fn () => CardTemplate::factory()->create([
        'tenant_id' => $this->tenant->id, 'width' => 85.6, 'height' => 54, 'size_unit' => 'mm',
    ]));
    $this->url = "/api/v1/card-templates/{$this->template->public_id}/elements";
    $this->biometrics = function (array $biometrics): void {
        $this->tenant->update(['settings' => ['biometrics' => $biometrics]]);
        $this->template->unsetRelation('tenant');
        $this->owner->unsetRelation('tenant');
    };
    $this->printing = ['enabled' => true, 'print_on_card' => true, 'modalities' => ['fingerprint' => ['mode' => 'optional', 'positions' => ['right_thumb'], 'drivers' => ['upload']]]];
    $this->element = fn (array $overrides = []): array => [
        'side' => 'front', 'type' => 'biometric', 'field' => 'biometric.fingerprint.auto',
        'x' => 60, 'y' => 30, 'width' => 18, 'height' => 20, 'style' => ['fit' => 'contain'], ...$overrides,
    ];
    Sanctum::actingAs($this->owner);
});

it('offers no biometric element while the organization does not print biometrics on cards', function () {
    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('meta.catalog.biometric_fields', [])
        ->assertJsonPath('meta.catalog.types.biometric', ['image' => true, 'needs_biometric' => true]);

    // On, but printing on cards not allowed
    ($this->biometrics)([...$this->printing, 'print_on_card' => false]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('meta.catalog.biometric_fields', []);
    $this->putJson($this->url, ['elements' => [($this->element)()]])
        ->assertJsonValidationErrors(['elements.0.field' => 'does not print biometrics on cards']);
});

it('offers the first captured in the order of preference, or any position, of printable kinds in use', function () {
    ($this->biometrics)([...$this->printing, 'modalities' => [...$this->printing['modalities'], 'face' => ['mode' => 'required', 'positions' => ['face'], 'drivers' => ['webcam']]]]);

    $fields = $this->getJson($this->url)->assertOk()->json('meta.catalog.biometric_fields');

    // Face is in use but not printable (registry), so only fingerprints are offered; any finger may be a stand-in
    expect($fields)->toHaveKeys(['fingerprint'])->not->toHaveKey('face')
        ->and($fields['fingerprint'][0])->toBe('biometric.fingerprint.auto')
        ->and($fields['fingerprint'])->toHaveCount(11)->toContain('biometric.fingerprint.left_little');
});

it('saves a biometric element with its image choice and fallback style, and counts a change of finger as a design change', function () {
    ($this->biometrics)($this->printing);

    $this->putJson($this->url, ['elements' => [($this->element)()]])->assertOk()
        ->assertJsonPath('meta.version', 2)
        ->assertJsonPath('data.0.type', 'biometric')
        ->assertJsonPath('data.0.field', 'biometric.fingerprint.auto')
        ->assertJsonPath('data.0.style.fit', 'contain');

    $this->putJson($this->url, ['elements' => [($this->element)(['field' => 'biometric.fingerprint.left_index'])]])->assertOk()
        ->assertJsonPath('meta.version', 3)
        ->assertJsonPath('data.0.field', 'biometric.fingerprint.left_index');
});

it('refuses a biometric element without a choice, of a kind not printed, or of an unknown position', function () {
    ($this->biometrics)($this->printing);

    $this->putJson($this->url, ['elements' => [
        ($this->element)(['field' => null]),
        ($this->element)(['field' => 'biometric.face.face']),
        ($this->element)(['field' => 'biometric.fingerprint.tail']),
        ($this->element)(['field' => 'full_name']),
    ]])->assertJsonValidationErrors([
        'elements.0.field' => 'Choose which biometric image',
        'elements.1.field' => 'cannot be printed',
        'elements.2.field' => 'cannot be printed',
        'elements.3.field' => 'cannot be printed',
    ]);

    // Fingerprints turned off: not offered, even though the registry allows printing them
    ($this->biometrics)([...$this->printing, 'modalities' => ['fingerprint' => ['mode' => 'off'], 'face' => ['mode' => 'optional', 'positions' => ['face'], 'drivers' => ['webcam']]]]);
    $this->putJson($this->url, ['elements' => [($this->element)()]])->assertJsonValidationErrors('elements.0.field');
});

it('offers a printable kind added to the registry without any code change', function () {
    config([
        'biometrics.modalities.palm' => ['positions' => ['right_palm' => 22, 'left_palm' => 24], 'image_formats' => ['png'], 'template_formats' => [], 'printable' => true],
        'biometrics.drivers.bridge.modalities' => ['fingerprint', 'face', 'iris', 'palm'],
    ]);
    ($this->biometrics)([...$this->printing, 'modalities' => ['fingerprint' => ['mode' => 'off'], 'palm' => ['mode' => 'optional', 'positions' => ['left_palm'], 'drivers' => ['bridge']]]]);

    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('meta.catalog.biometric_fields', ['palm' => ['biometric.palm.auto', 'biometric.palm.right_palm', 'biometric.palm.left_palm']]);
    $this->putJson($this->url, ['elements' => [($this->element)(['field' => 'biometric.palm.left_palm'])]])->assertOk()
        ->assertJsonPath('data.0.field', 'biometric.palm.left_palm');
});
