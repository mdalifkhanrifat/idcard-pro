<?php

use App\Models\CardBiometricImage;
use App\Services\Biometrics\BiometricVault;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
 * A kind of biometric added to the registry only (config/biometrics.php), with no code change, goes all the
 * way: organization settings, requirements before issuing, enrollment, stored status and list filter, the card
 * designer, the card's own copy at issue and the image the card prints.
 */
beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    Storage::fake(config('biometrics.disk'));
    config([
        'biometrics.modalities.palm' => ['positions' => ['right_palm' => 22, 'left_palm' => 24], 'image_formats' => ['png'], 'template_formats' => [], 'printable' => true],
        'biometrics.drivers.bridge.modalities' => ['fingerprint', 'face', 'iris', 'palm'],
    ]);
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP']);
    $this->holder = cardholder($this->tenant, ['full_name' => 'Palm Person']);
    Sanctum::actingAs($this->owner);
});

it('takes a kind added to the registry through settings, enrollment, status, design, issue and printing', function () {
    $this->post('/api/v1/settings', ['name' => 'Acme', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233', 'biometrics' => [
        'enabled' => '1', 'required_before' => ['issue'], 'print_on_card' => '1', 'print_source' => 'issue',
        'modalities' => ['fingerprint' => ['mode' => 'off'], 'palm' => ['mode' => 'required', 'count' => 1, 'positions' => ['left_palm'], 'drivers' => ['bridge']]],
    ]], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.biometrics.modalities.palm.mode', 'required');
    $this->owner->unsetRelation('tenant');

    // Designer: the new kind is offered and saved
    $this->getJson("/api/v1/card-templates/{$this->template->public_id}/elements")->assertOk()
        ->assertJsonPath('meta.catalog.biometric_fields', ['palm' => ['biometric.palm.auto', 'biometric.palm.right_palm', 'biometric.palm.left_palm']]);
    $this->putJson("/api/v1/card-templates/{$this->template->public_id}/elements", ['elements' => [
        ['side' => 'front', 'type' => 'biometric', 'field' => 'biometric.palm.auto', 'x' => 5, 'y' => 5, 'width' => 20, 'height' => 20],
    ]])->assertOk();

    // Required before issuing: held up until captured
    $issue = fn () => $this->postJson("/api/v1/cardholders/{$this->holder->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new']);
    $issue()->assertUnprocessable()->assertJsonPath('requirements.0.code', 'biometrics');
    $this->getJson('/api/v1/cardholders?filter[biometrics]=incomplete')->assertOk()->assertJsonPath('data.0.full_name', 'Palm Person');

    ob_start();
    imagepng(imagecreatetruecolor(24, 30));
    $palm = (string) ob_get_clean();
    $this->postJson("/api/v1/cardholders/{$this->holder->public_id}/biometrics", [
        'modality' => 'palm', 'position' => 'left_palm', 'driver' => 'bridge', 'image_base64' => base64_encode($palm), 'image_format' => 'png',
    ])->assertCreated()
        ->assertJsonPath('meta.status.complete', true)
        ->assertJsonPath('meta.status.modalities.palm.counted', 1);
    $this->getJson('/api/v1/cardholders?filter[biometrics]=complete')->assertOk()->assertJsonCount(1, 'data');

    // Issued with its own copy; it prints that image, with no warning
    $card = $issue()->assertCreated();
    expect(collect($card->json('meta.warnings'))->where('type', 'missing_biometric'))->toBeEmpty();
    $kept = CardBiometricImage::query()->withoutGlobalScopes()->sole();
    expect([$kept->modality, $kept->position])->toBe(['palm', 'left_palm'])
        ->and(app(BiometricVault::class)->get((string) $kept->disk, (string) $kept->path))->toBe($palm);

    $url = $this->getJson("/api/v1/cards/{$card->json('data.id')}")->json('meta.values.images.biometrics')['biometric.palm.auto'];
    expect($this->get($url)->assertOk()->getContent())->toBe($palm);
});
