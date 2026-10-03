<?php

use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost:8080');
    [$this->tenant, $this->owner] = tenantWithOwner();
});

function enableTwoFactor(object $test): string
{
    $test->actingAs($test->owner, 'web');

    $secret = $test->postJson('/api/v1/auth/two-factor', ['password' => TEST_PASSWORD])
        ->assertOk()
        ->assertJsonStructure(['data' => ['secret', 'qr_svg']])
        ->json('data.secret');

    $test->postJson('/api/v1/auth/two-factor/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertOk()
        ->assertJsonCount(8, 'data.recovery_codes');

    app('auth')->forgetGuards();
    $test->flushSession();

    return $secret;
}

it('requires the authenticator code after the password once enabled', function () {
    $secret = enableTwoFactor($this);

    $this->postJson('/api/v1/auth/login', ['organization' => 'acme', 'login' => 'owner', 'password' => TEST_PASSWORD])
        ->assertOk()
        ->assertJson(['two_factor' => true]);
    $this->assertGuest();

    $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => '000000'])
        ->assertJsonValidationErrors('code');
    $this->assertGuest();

    $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertOk();
    $this->assertAuthenticatedAs($this->owner);
});

it('accepts each recovery code only once', function () {
    enableTwoFactor($this);
    $code = $this->owner->fresh()->two_factor_recovery_codes[0];

    $this->postJson('/api/v1/auth/login', ['organization' => 'acme', 'login' => 'owner', 'password' => TEST_PASSWORD]);
    $this->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => $code])->assertOk();

    app('auth')->forgetGuards();
    $this->flushSession();

    $this->postJson('/api/v1/auth/login', ['organization' => 'acme', 'login' => 'owner', 'password' => TEST_PASSWORD]);
    $this->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => $code])->assertJsonValidationErrors('code');
});

it('rejects a challenge without a pending password step', function () {
    $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => '123456'])->assertJsonValidationErrors('code');
});

it('stores the secret encrypted', function () {
    enableTwoFactor($this);

    $raw = DB::table('users')->where('id', $this->owner->id)->value('two_factor_secret');

    expect($raw)->not->toBe($this->owner->fresh()->two_factor_secret)
        ->and(app(TwoFactorAuthenticator::class)->generateRecoveryCodes())->toHaveCount(8);
});
