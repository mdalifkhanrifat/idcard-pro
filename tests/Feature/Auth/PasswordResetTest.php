<?php

use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:8080');
});

it('resets a password with a single-use token', function () {
    Notification::fake();
    [, $owner] = tenantWithOwner();
    $token = '';

    $this->postJson('/api/v1/auth/forgot-password', ['organization' => 'acme', 'email' => 'owner@example.com'])->assertOk();

    Notification::assertSentTo($owner, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    expect(DB::table('password_reset_tokens')->value('token_hash'))->toBe(hash('sha256', $token));

    $payload = ['token' => $token, 'password' => 'New#Password456', 'password_confirmation' => 'New#Password456'];
    $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
    $this->postJson('/api/v1/auth/reset-password', $payload)->assertJsonValidationErrors('token');

    expect(Hash::check('New#Password456', $owner->fresh()->password))->toBeTrue();
});

it('does not reveal whether an account exists', function () {
    Notification::fake();
    tenantWithOwner();

    $this->postJson('/api/v1/auth/forgot-password', ['organization' => 'acme', 'email' => 'nobody@example.com'])
        ->assertOk()
        ->assertJson(['message' => __('auth.reset_link_sent')]);

    Notification::assertNothingSent();
});

it('enforces the password policy', function () {
    $this->postJson('/api/v1/auth/reset-password', ['token' => str_repeat('a', 64), 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertJsonValidationErrors('password');
});
