<?php

use App\Enums\LoginEventType;
use App\Services\Audit\AuditService;
use Carbon\CarbonImmutable;
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

it('keeps what a user did after that user is deleted, with their name, on the page and by the who filter', function () {
    $operator = tenantUser($this->tenant, 'Operator');

    Sanctum::actingAs($operator);
    $this->postJson('/api/v1/cardholders', ['full_name' => 'Made Before Leaving'])->assertCreated();

    Sanctum::actingAs($this->owner);
    $this->deleteJson("/api/v1/users/{$operator->public_id}")->assertNoContent();

    $this->getJson('/api/v1/audit/activities?filter[log_name]=cardholders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.causer.name', $operator->name);
    $this->getJson("/api/v1/audit/activities?filter[causer]={$operator->public_id}&filter[log_name]=cardholders")
        ->assertJsonCount(1, 'data');
    expect($this->getJson('/api/v1/reports/audit-changes?filter[log_name]=cardholders&columns[]=audit.who')->json('data.0.values')['audit.who'])->toBe($operator->name);
});

it('shows a role given and a permission changed like any other change, old and new', function () {
    Sanctum::actingAs($this->owner);
    $id = $this->postJson('/api/v1/roles', ['name' => 'Clerk', 'permissions' => ['users.view']])->assertCreated()->json('data.id');
    $this->putJson("/api/v1/roles/{$id}", ['name' => 'Clerk', 'permissions' => ['users.view', 'cards.view']])->assertOk();
    $operator = tenantUser($this->tenant, 'Operator');
    $this->putJson("/api/v1/users/{$operator->public_id}", ['name' => $operator->name, 'email' => $operator->email, 'status' => 'active', 'roles' => ['Viewer']])->assertOk();

    $byRole = $this->getJson('/api/v1/audit/activities?filter[log_name]=roles&filter[event]=updated')->assertOk()->json('data.0.fields');
    expect($byRole)->toBe([['key' => 'permissions', 'label' => 'Permissions', 'old' => 'Users: View', 'new' => 'Issued cards: View, Users: View']]);

    $byUser = $this->getJson('/api/v1/audit/activities?filter[log_name]=user')->assertOk()->json('data.0.fields');
    expect($byUser)->toBe([['key' => 'roles', 'label' => 'Roles', 'old' => 'Operator', 'new' => 'Viewer']]);

    expect($this->getJson('/api/v1/reports/audit-changes?filter[log_name]=user&columns[]=audit.changes')->json('data.0.values')['audit.changes'])->toBe('Roles: Operator → Viewer');
});

it('lists in the filter every kind of record this organization changed, including new modules', function () {
    Sanctum::actingAs($this->otherOwner);
    $this->postJson('/api/v1/cardholders', ['full_name' => 'Beta Person'])->assertCreated();

    Sanctum::actingAs($this->owner);
    $this->putJson('/api/v1/auth/me', ['name' => 'Alpha Renamed'])->assertOk();
    // Only this organization's: its users' changes and its own creation by the system, never the other's cardholder
    $this->getJson('/api/v1/audit/activities')->assertOk()->assertJsonPath('meta.log_names', ['tenant', 'user'])
        ->assertJsonPath('meta.events', ['created', 'updated']);
    $this->getJson('/api/v1/audit/activities?filter[event]=created')->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject_type', 'Tenant')
        ->assertJsonPath('data.0.causer', null)
        ->assertJsonPath('data.0.changes.attributes.slug', 'alpha');

    $this->postJson('/api/v1/cardholders', ['full_name' => 'Alpha Person'])->assertCreated();
    $this->getJson('/api/v1/audit/activities?filter[log_name]=user')->assertJsonCount(1, 'data')->assertJsonPath('meta.log_names', ['cardholders', 'tenant', 'user'])->assertJsonPath('meta.events', ['created', 'updated']);
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

it('filters changes and sign-ins by the organization\'s days, not UTC days', function () {
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->owner->setRelation('tenant', $this->tenant);
    $request = Request::create('/');
    Sanctum::actingAs($this->owner);
    // 1 April, 01:30 in Dhaka is still 31 March in UTC
    $this->travelTo(CarbonImmutable::parse('2026-04-01 01:30', 'Asia/Dhaka'));
    $this->putJson('/api/v1/auth/me', ['name' => 'Early riser'])->assertOk();
    app(AuditService::class)->recordLogin(LoginEventType::Succeeded, $request, $this->owner, $this->tenant, 'a@example.com');
    // 1 April, 23:30 in Dhaka is 1 April in UTC too
    $this->travelTo(CarbonImmutable::parse('2026-04-01 23:30', 'Asia/Dhaka'));
    app(AuditService::class)->recordLogin(LoginEventType::Failed, $request, $this->owner, $this->tenant, 'a@example.com');
    $this->travelBack();

    $this->getJson('/api/v1/audit/activities?filter[event]=updated&filter[date_from]=2026-04-01&filter[date_to]=2026-04-01')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/audit/activities?filter[event]=updated&filter[date_to]=2026-03-31')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/audit/logins?filter[date_from]=2026-04-01&filter[date_to]=2026-04-01')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/audit/logins?filter[date_from]=2026-04-02')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/audit/logins?filter[date_from]=2026-04-02&filter[date_to]=2026-04-01')->assertJsonValidationErrors('filter.date_to');
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

    // The same changes for people: each by its name in the reader's language, old and new as text
    $fields = collect($logs[0]['fields'])->keyBy('key');
    expect($fields['settings.biometrics.modalities.fingerprint.positions'])->toBe(['key' => 'settings.biometrics.modalities.fingerprint.positions', 'label' => 'Fingerprint — Positions', 'old' => 'Right thumb, Left thumb', 'new' => 'Right index, Left index'])
        ->and($fields['settings.biometrics.enabled'])->toMatchArray(['label' => 'Capturing biometrics', 'old' => 'No', 'new' => 'Yes']);
    $this->owner->update(['locale' => 'bn']);
    expect(collect($tenantLogs()[0]['fields'])->keyBy('key')['settings.biometrics.modalities.fingerprint.positions']['label'])->toBe('আঙুলের ছাপ — অবস্থান');
    $this->owner->update(['locale' => 'en']);

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
