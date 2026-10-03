<?php

use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    Sanctum::actingAs($this->owner);
});

it('updates the own profile', function () {
    $this->putJson('/api/v1/auth/me', ['name' => 'New Name', 'phone' => '01700000000', 'locale' => 'bn'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.locale', 'bn');
});

it('changes the password only with the current one', function () {
    $this->putJson('/api/v1/auth/password', ['current_password' => 'wrong', 'password' => 'Brand#New9876', 'password_confirmation' => 'Brand#New9876'])
        ->assertJsonValidationErrors('current_password');

    $this->putJson('/api/v1/auth/password', ['current_password' => TEST_PASSWORD, 'password' => 'Brand#New9876', 'password_confirmation' => 'Brand#New9876'])
        ->assertOk();

    expect(Hash::check('Brand#New9876', $this->owner->fresh()->password))->toBeTrue();
});

it('creates, lists and revokes API tokens', function () {
    $created = $this->postJson('/api/v1/auth/tokens', ['name' => 'HR sync', 'expires_in_days' => 30])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'plain_text_token']])
        ->json('data');

    $this->getJson('/api/v1/auth/tokens')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'HR sync');

    $this->deleteJson("/api/v1/auth/tokens/{$created['id']}")->assertNoContent();
    expect($this->owner->tokens()->count())->toBe(0);
});

it('lets a token authenticate API requests', function () {
    $plain = $this->owner->createToken('integration')->plainTextToken;
    app('auth')->forgetGuards();

    $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.tenant.slug', 'acme');
});
