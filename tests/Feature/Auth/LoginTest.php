<?php

use App\Enums\LoginEventType;
use App\Models\LoginEvent;
use App\Models\User;

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:8080');
});

it('signs a tenant user in with organization and e-mail', function () {
    [$tenant, $owner] = tenantWithOwner();

    $this->postJson('/api/v1/auth/login', ['organization' => 'acme', 'login' => 'owner@example.com', 'password' => TEST_PASSWORD])
        ->assertOk()
        ->assertJson(['two_factor' => false]);

    $this->assertAuthenticatedAs($owner);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.tenant.slug', 'acme')
        ->assertJsonPath('data.user.email', 'owner@example.com')
        ->assertJsonFragment(['users.create']);

    expect(LoginEvent::where('event', LoginEventType::Succeeded)->count())->toBe(1);
});

it('signs in with a username', function () {
    tenantWithOwner();

    $this->postJson('/api/v1/auth/login', ['organization' => 'ACME', 'login' => 'owner', 'password' => TEST_PASSWORD])
        ->assertOk();

    $this->assertAuthenticated();
});

it('returns the same generic error for unknown organization, user or wrong password', function (array $payload) {
    tenantWithOwner();

    $this->postJson('/api/v1/auth/login', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['login' => __('auth.failed')]);

    $this->assertGuest();
})->with([
    'unknown organization' => [['organization' => 'nope', 'login' => 'owner@example.com', 'password' => TEST_PASSWORD]],
    'unknown user' => [['organization' => 'acme', 'login' => 'ghost@example.com', 'password' => TEST_PASSWORD]],
    'wrong password' => [['organization' => 'acme', 'login' => 'owner@example.com', 'password' => 'wrong']],
    'tenant user without organization' => [['login' => 'owner@example.com', 'password' => TEST_PASSWORD]],
]);

it('locks the account after too many failures, even with the right password afterwards', function () {
    config(['security.lockout.max_attempts' => 3]);
    [, $owner] = tenantWithOwner();

    foreach (range(1, 3) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['organization' => 'acme', 'login' => 'owner', 'password' => 'wrong'])->assertUnprocessable();
    }

    expect($owner->fresh()->isLocked())->toBeTrue();

    $this->postJson('/api/v1/auth/login', ['organization' => 'acme', 'login' => 'owner', 'password' => TEST_PASSWORD])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('login');

    $this->assertGuest();
    expect(LoginEvent::where('event', LoginEventType::Locked)->exists())->toBeTrue();
});

it('refuses inactive users and suspended organizations', function () {
    [$tenant] = tenantWithOwner();
    tenantUser($tenant, 'Operator', ['email' => 'off@example.com', 'status' => 'inactive']);

    $this->postJson('/api/v1/auth/login', ['organization' => 'acme', 'login' => 'off@example.com', 'password' => TEST_PASSWORD])
        ->assertJsonValidationErrors(['login' => __('auth.inactive')]);

    $tenant->update(['status' => 'suspended']);

    $this->postJson('/api/v1/auth/login', ['organization' => 'acme', 'login' => 'owner@example.com', 'password' => TEST_PASSWORD])
        ->assertJsonValidationErrors(['login' => __('auth.inactive')]);

    $this->assertGuest();
});

it('keeps identical e-mails in different organizations separate', function () {
    [, $ownerA] = tenantWithOwner('alpha', 'shared@example.com');
    [, $ownerB] = tenantWithOwner('beta', 'shared@example.com');

    $this->postJson('/api/v1/auth/login', ['organization' => 'beta', 'login' => 'shared@example.com', 'password' => TEST_PASSWORD])->assertOk();

    $this->assertAuthenticatedAs($ownerB);
});

it('signs a platform administrator in without an organization', function () {
    $admin = User::factory()->platformAdmin()->create(['email' => 'root@example.com', 'password' => TEST_PASSWORD]);

    $this->postJson('/api/v1/auth/login', ['login' => 'root@example.com', 'password' => TEST_PASSWORD])->assertOk();

    $this->assertAuthenticatedAs($admin);
});

it('logs out', function () {
    tenantWithOwner();
    $this->postJson('/api/v1/auth/login', ['organization' => 'acme', 'login' => 'owner', 'password' => TEST_PASSWORD]);

    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->assertGuest('web');
});
