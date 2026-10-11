<?php

use App\Enums\LoginEventType;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner('alpha', 'a@example.com');
    [$this->other, $this->otherOwner] = tenantWithOwner('beta', 'b@example.com');
});

it('shows only changes made by users of the current organization', function () {
    Sanctum::actingAs($this->owner);
    $this->putJson('/api/v1/auth/me', ['name' => 'Alpha Renamed'])->assertOk();

    Sanctum::actingAs($this->otherOwner);
    $this->putJson('/api/v1/auth/me', ['name' => 'Beta Renamed'])->assertOk();

    Sanctum::actingAs($this->owner);
    $this->getJson('/api/v1/audit/activities?filter[event]=updated')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.causer.email', 'a@example.com');
});

it('lists in the filter every kind of record this organization changed, including new modules', function () {
    Sanctum::actingAs($this->otherOwner);
    $this->postJson('/api/v1/cardholders', ['full_name' => 'Beta Person'])->assertCreated();

    Sanctum::actingAs($this->owner);
    $this->putJson('/api/v1/auth/me', ['name' => 'Alpha Renamed'])->assertOk();
    $this->getJson('/api/v1/audit/activities')->assertOk()->assertJsonPath('meta.log_names', ['user'])
        // Events too: only this organization's (the other one's "created" is not listed)
        ->assertJsonPath('meta.events', ['updated']);

    $this->postJson('/api/v1/cardholders', ['full_name' => 'Alpha Person'])->assertCreated();
    $this->getJson('/api/v1/audit/activities?filter[log_name]=user')->assertJsonCount(1, 'data')->assertJsonPath('meta.log_names', ['cardholders', 'user'])->assertJsonPath('meta.events', ['created', 'updated']);
    $this->getJson('/api/v1/audit/activities?filter[event]=Robert\'); DROP')->assertJsonValidationErrors('filter.event');
});

it('filters the sign-in history of the current organization', function () {
    $request = Request::create('/', server: ['REMOTE_ADDR' => '10.0.0.7']);
    $audit = app(AuditService::class);
    $audit->recordLogin(LoginEventType::Failed, $request, $this->owner, $this->tenant, 'a@example.com');
    $audit->recordLogin(LoginEventType::Succeeded, $request, $this->owner, $this->tenant, 'a@example.com');
    $audit->recordLogin(LoginEventType::Failed, $request, $this->otherOwner, $this->other, 'b@example.com');
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/audit/logins')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/audit/logins?filter[event]=failed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.ip_address', '10.0.0.7');
    $this->getJson('/api/v1/audit/logins?filter[event]=bogus')->assertJsonValidationErrors('filter.event');
});

it('requires the audit permission', function () {
    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));

    $this->getJson('/api/v1/audit/activities')->assertForbidden();
    $this->getJson('/api/v1/audit/logins')->assertForbidden();
});

it('logs who changed which organization settings, old and new, without the unchanged ones', function () {
    Sanctum::actingAs($this->owner);
    $save = fn (array $extra) => $this->post('/api/v1/settings', ['name' => 'Alpha', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233', ...$extra], ['Accept' => 'application/json'])->assertOk();
    $tenantLogs = fn () => $this->getJson('/api/v1/audit/activities?filter[log_name]=tenant')->assertOk()->json('data');
    $save([]);
    $this->owner->unsetRelation('tenant');
    $before = count($tenantLogs());

    $save(['card_number_format' => '{CODE}-{SEQ:5}', 'biometrics' => [
        'enabled' => '1', 'print_on_card' => '1',
        'modalities' => ['fingerprint' => ['mode' => 'required', 'count' => 2, 'positions' => ['right_index', 'left_index'], 'substitutes' => '1', 'min_quality' => 40, 'max_attempts' => 3, 'drivers' => ['upload']]],
    ]]);
    $this->owner->unsetRelation('tenant');

    $logs = $tenantLogs();
    expect($logs)->toHaveCount($before + 1);
    $changes = $logs[0]['changes'];
    expect($logs[0]['causer']['email'])->toBe('a@example.com')
        ->and($changes['attributes'])->toMatchArray([
            'settings.card_number_format' => '{CODE}-{SEQ:5}',
            'settings.biometrics.enabled' => true,
            'settings.biometrics.print_on_card' => true,
            'settings.biometrics.modalities.fingerprint.mode' => 'required',
            // A list is one value
            'settings.biometrics.modalities.fingerprint.positions' => ['right_index', 'left_index'],
        ])
        ->and($changes['old'])->toMatchArray([
            'settings.biometrics.enabled' => false,
            'settings.biometrics.print_on_card' => false,
            'settings.biometrics.modalities.fingerprint.positions' => ['right_thumb', 'left_thumb'],
        ])
        // Unchanged settings are left out, and the whole JSON is never stored
        ->and($changes['attributes'])->not->toHaveKeys(['settings', 'settings.biometrics.modalities.face.mode', 'settings.biometrics.family_members']);

    // Saving the same again records nothing
    $save(['card_number_format' => '{CODE}-{SEQ:5}']);
    expect($tenantLogs())->toHaveCount($before + 1);
});

it('logs a new organization logo', function () {
    Storage::fake('public');
    Sanctum::actingAs($this->owner);

    $this->post('/api/v1/settings', ['name' => 'Alpha', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233', 'logo' => UploadedFile::fake()->image('logo.png', 40, 40)], ['Accept' => 'application/json'])->assertOk();

    $changes = $this->getJson('/api/v1/audit/activities?filter[log_name]=tenant')->json('data.0.changes');
    expect($changes['attributes']['logo_path'])->toStartWith('tenants/')->and($changes['old']['logo_path'])->toBeNull();
});
