<?php

use App\Models\Tenant;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->settings = fn (array $biometrics) => $this->post('/api/v1/settings', ['name' => 'Acme', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233', 'biometrics' => $biometrics], ['Accept' => 'application/json']);
    $this->fingerprint = ['mode' => 'required', 'count' => 2, 'positions' => ['right_index', 'left_index'], 'substitutes' => '1', 'min_quality' => '60', 'max_attempts' => '4', 'drivers' => ['secugen', 'upload']];
    Sanctum::actingAs($this->owner);
});

it('keeps biometrics off until the organization turns it on, and lists what can be captured and with what', function () {
    $response = $this->getJson('/api/v1/settings')->assertOk()
        ->assertJsonPath('data.biometrics.enabled', false)
        ->assertJsonPath('data.biometrics.print_on_card', false)
        ->assertJsonPath('data.biometrics.required_before', [])
        ->assertJsonPath('data.biometrics.modalities.fingerprint.positions', ['right_thumb', 'left_thumb'])
        ->assertJsonPath('data.biometrics.modalities.face.mode', 'off')
        ->assertJsonPath('data.biometrics.modalities.iris.mode', 'off');

    expect($response->json('data.biometric_options.modalities.fingerprint.positions'))->toHaveCount(10)
        ->and($response->json('data.biometric_options.modalities.face.drivers'))->toBe(['upload', 'simulator', 'webcam', 'bridge'])
        ->and($response->json('data.biometric_options.modalities.fingerprint.printable'))->toBeTrue()
        ->and($response->json('data.biometric_options.drivers.secugen.local_url'))->toBe('https://localhost:8443')
        ->and($response->json('data.biometric_options.drivers.simulator.testing'))->toBeTrue()
        ->and($response->json('data.biometric_options.drivers.secugen.testing'))->toBeFalse()
        // Screens lay positions out from the registry's groups (a row per hand), not from code
        ->and($response->json('data.biometric_options.modalities.fingerprint.groups.right_hand'))->toBe(['right_thumb', 'right_index', 'right_middle', 'right_ring', 'right_little'])
        ->and($response->json('data.biometric_options.modalities.face.groups'))->toBe([])
        // Stations check files the way the server does, from the same registry
        ->and($response->json('data.biometric_options.modalities.face.image_formats'))->toBe(['jpeg', 'png'])
        ->and($response->json('data.biometric_options.modalities.face.template_formats'))->toBe([])
        ->and($response->json('data.biometric_options.modalities.fingerprint.template_formats'))->toContain('iso-19794-2')
        ->and($response->json('data.biometric_options.image_types.png'))->toBe(['signature' => '89504e47', 'mime' => 'image/png', 'displayable' => true])
        ->and($response->json('data.biometric_options.max_image_kb'))->toBe(2048)
        ->and($response->json('data.biometric_options.unavailable_reasons_with_note'))->toBe(['other']);
});

it('only lists group positions the registry offers', function () {
    config(['biometrics.modalities.fingerprint.groups.left_hand' => ['left_thumb', 'left_tail'], 'biometrics.modalities.fingerprint.groups.paws' => ['tail']]);

    $response = $this->getJson('/api/v1/settings')->assertOk();

    expect($response->json('data.biometric_options.modalities.fingerprint.groups'))->toHaveKeys(['right_hand', 'left_hand'])->not->toHaveKey('paws')
        ->and($response->json('data.biometric_options.modalities.fingerprint.groups.left_hand'))->toBe(['left_thumb']);
});

it('saves which fingers, how many, the quality and the drivers, sent as a form', function () {
    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => $this->fingerprint], 'family_members' => '1', 'required_before' => ['issue'], 'print_on_card' => '1'])
        ->assertOk()
        ->assertJsonPath('data.biometrics.enabled', true)
        ->assertJsonPath('data.biometrics.modalities.fingerprint', [
            'mode' => 'required', 'count' => 2, 'positions' => ['right_index', 'left_index'], 'substitutes' => true,
            'min_quality' => 60, 'max_attempts' => 4, 'drivers' => ['secugen', 'upload'],
        ])
        ->assertJsonPath('data.biometrics.modalities.face.mode', 'off')
        ->assertJsonPath('data.biometrics.family_members', true)
        ->assertJsonPath('data.biometrics.required_before', ['issue'])
        ->assertJsonPath('data.biometrics.print_on_card', true);

    // Face only, three tries; fingerprints not sent stay as they were, and the missing list means none
    ($this->settings)(['enabled' => '1', 'modalities' => ['face' => ['mode' => 'optional', 'positions' => ['face'], 'drivers' => ['webcam']]]])
        ->assertOk()
        ->assertJsonPath('data.biometrics.modalities.face.mode', 'optional')
        ->assertJsonPath('data.biometrics.modalities.face.drivers', ['webcam'])
        ->assertJsonPath('data.biometrics.modalities.fingerprint.positions', ['right_index', 'left_index'])
        ->assertJsonPath('data.biometrics.required_before', []);
});

it('allows a person without fingers to be enrolled by the face alone, and biometrics with nothing in use is refused', function () {
    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => ['mode' => 'off'], 'face' => ['mode' => 'required', 'positions' => ['face'], 'drivers' => ['webcam']]]])
        ->assertOk()
        ->assertJsonPath('data.biometrics.modalities.fingerprint.mode', 'off')
        ->assertJsonPath('data.biometrics.modalities.face.mode', 'required');

    ($this->settings)(['enabled' => '1', 'modalities' => ['face' => ['mode' => 'off']]])->assertJsonValidationErrors('biometrics.modalities');
    // Turning biometrics off is always possible
    ($this->settings)(['enabled' => '0', 'modalities' => ['face' => ['mode' => 'off']]])->assertOk()->assertJsonPath('data.biometrics.enabled', false);
});

it('refuses positions, drivers and counts that cannot work', function () {
    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => [...$this->fingerprint, 'positions' => ['right_index', 'tail'], 'drivers' => ['webcam']]]])
        ->assertJsonValidationErrors(['biometrics.modalities.fingerprint.positions.1', 'biometrics.modalities.fingerprint.drivers.0']);
    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => [...$this->fingerprint, 'count' => 3, 'substitutes' => '0']]])
        ->assertJsonValidationErrors('biometrics.modalities.fingerprint.count');
    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => [...$this->fingerprint, 'positions' => null, 'drivers' => null]]])
        ->assertJsonValidationErrors(['biometrics.modalities.fingerprint.positions', 'biometrics.modalities.fingerprint.drivers']);
    ($this->settings)(['enabled' => '1', 'modalities' => ['palm' => ['mode' => 'required']], 'required_before' => ['lunch']])
        ->assertJsonValidationErrors(['biometrics.modalities', 'biometrics.required_before.0']);
    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => [...$this->fingerprint, 'min_quality' => 101, 'max_attempts' => 0]]])
        ->assertJsonValidationErrors(['biometrics.modalities.fingerprint.min_quality', 'biometrics.modalities.fingerprint.max_attempts']);

    // With stand-ins allowed, more can be asked for than the preferred positions
    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => [...$this->fingerprint, 'count' => 4]]])
        ->assertOk()->assertJsonPath('data.biometrics.modalities.fingerprint.count', 4);
});

it('never blocks a save with settings kept while biometrics are off, and checks them when turned on', function () {
    config(['biometrics.simulator' => false]);
    // Saved earlier with a driver this installation no longer offers
    $this->tenant->update(['settings' => ['biometrics' => ['enabled' => false, 'modalities' => ['fingerprint' => ['mode' => 'required', 'positions' => ['right_thumb'], 'drivers' => ['simulator']]]]]]);

    ($this->settings)(['enabled' => '0', 'family_members' => '1'])->assertOk()->assertJsonPath('data.biometrics.family_members', true);
    $this->owner->unsetRelation('tenant');
    ($this->settings)(['enabled' => '1'])->assertJsonValidationErrors('biometrics.modalities.fingerprint.drivers');
});

it('takes iris capture from the settings like any other kind', function () {
    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => ['mode' => 'off'], 'iris' => ['mode' => 'required', 'count' => 1, 'positions' => ['left_iris'], 'drivers' => ['bridge']]]])
        ->assertOk()
        ->assertJsonPath('data.biometrics.modalities.iris', [
            'mode' => 'required', 'count' => 1, 'positions' => ['left_iris'], 'substitutes' => false,
            'min_quality' => 40, 'max_attempts' => 3, 'drivers' => ['bridge'],
        ]);
    ($this->settings)(['enabled' => '1', 'modalities' => ['iris' => ['mode' => 'required', 'positions' => ['left_iris'], 'drivers' => ['webcam']]]])
        ->assertJsonValidationErrors('biometrics.modalities.iris.drivers.0');
});

it('offers a kind added to the registry without any code change', function () {
    config([
        'biometrics.modalities.palm' => ['positions' => ['right_palm' => 22, 'left_palm' => 24], 'image_formats' => ['png'], 'template_formats' => [], 'printable' => false],
        'biometrics.drivers.bridge.modalities' => ['fingerprint', 'face', 'iris', 'palm'],
    ]);

    $this->getJson('/api/v1/settings')->assertOk()
        ->assertJsonPath('data.biometrics.modalities.palm.mode', 'off')
        ->assertJsonPath('data.biometric_options.modalities.palm.positions', ['right_palm', 'left_palm'])
        ->assertJsonPath('data.biometric_options.modalities.palm.drivers', ['bridge']);
    ($this->settings)(['enabled' => '1', 'modalities' => ['palm' => ['mode' => 'optional', 'count' => 2, 'positions' => ['right_palm', 'left_palm'], 'drivers' => ['bridge']]]])
        ->assertOk()->assertJsonPath('data.biometrics.modalities.palm.mode', 'optional');
});

it('never offers the simulator in production', function () {
    config(['biometrics.simulator' => false]);
    $this->tenant->update(['settings' => ['biometrics' => ['modalities' => ['fingerprint' => ['mode' => 'required', 'positions' => ['right_thumb'], 'drivers' => ['simulator', 'upload']]]]]]);

    $response = $this->getJson('/api/v1/settings')->assertOk()->assertJsonPath('data.biometrics.modalities.fingerprint.drivers', ['upload']);
    expect($response->json('data.biometric_options.drivers'))->not->toHaveKey('simulator');
    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => [...$this->fingerprint, 'drivers' => ['simulator']]]])
        ->assertJsonValidationErrors('biometrics.modalities.fingerprint.drivers.0');
});

it('lets only those who may change settings change biometrics', function () {
    Sanctum::actingAs(tenantUser($this->tenant, 'Administrator'));
    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => $this->fingerprint]])->assertForbidden();

    expect(Tenant::query()->find($this->tenant->id)->biometricSettings()['enabled'])->toBeFalse();
});
