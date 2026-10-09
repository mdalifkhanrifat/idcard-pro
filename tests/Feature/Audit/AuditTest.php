<?php

use App\Enums\LoginEventType;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
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
    $this->getJson('/api/v1/audit/activities')->assertOk()->assertJsonPath('meta.log_names', ['user']);

    $this->postJson('/api/v1/cardholders', ['full_name' => 'Alpha Person'])->assertCreated();
    $this->getJson('/api/v1/audit/activities?filter[log_name]=user')->assertJsonCount(1, 'data')->assertJsonPath('meta.log_names', ['cardholders', 'user']);
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
