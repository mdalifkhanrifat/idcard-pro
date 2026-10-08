<?php

use App\Enums\TenantStatus;
use App\Models\Card;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP']);
    $this->issue = fn () => $this->postJson('/api/v1/cardholders/'.cardholder($this->tenant)->public_id.'/cards', ['template' => $this->template->public_id, 'reason' => 'new'])->assertCreated()->json('data');
    $this->settings = fn (array $verification) => $this->post('/api/v1/settings', ['name' => 'Acme', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233', 'verification' => $verification], ['Accept' => 'application/json']);
    Sanctum::actingAs($this->owner);
});

it('gives every card its own unguessable verification code, kept for reprints', function () {
    [$first, $second] = [($this->issue)(), ($this->issue)()];
    $codeOf = fn (array $card) => Card::query()->withoutGlobalScopes()->where('public_id', $card['id'])->value('verify_code');
    $code = $codeOf($first);

    expect($code)->toMatch('/^[a-z0-9]{24}$/')->and($codeOf($second))->toMatch('/^[a-z0-9]{24}$/')->not->toBe($code);
    $this->postJson("/api/v1/cards/{$first['id']}/prints")->assertCreated();
    expect($codeOf($first))->toBe($code);
});

it('keeps verification off until the organization turns it on and chooses what the page shows', function () {
    $this->getJson('/api/v1/settings')->assertJsonPath('data.verification', [
        'enabled' => false,
        'fields' => ['photo', 'full_name', 'family.name', 'family.relation', 'designation', 'card.number', 'card.expiry_date'],
        'show_revoke_reason' => false,
        'link' => null,
        'note' => null,
    ])->assertJsonPath('data.verification_fields', config('cards.verification.fields'));
    $card = ($this->issue)();
    expect($this->getJson("/api/v1/cards/{$card['id']}")->json('meta.values.fields'))->not->toHaveKey('card.verify_url');

    ($this->settings)(['enabled' => true, 'fields' => ['full_name', 'card.number'], 'show_revoke_reason' => true, 'note' => ' Found it? Call the front desk. '])
        ->assertOk()
        ->assertJsonPath('data.verification.enabled', true)
        ->assertJsonPath('data.verification.fields', ['full_name', 'card.number'])
        ->assertJsonPath('data.verification.note', 'Found it? Call the front desk.');
    $code = Card::query()->withoutGlobalScopes()->where('public_id', $card['id'])->value('verify_code');
    expect($this->getJson("/api/v1/cards/{$card['id']}")->json('meta.values.fields')['card.verify_url'])->toBe(config('app.url')."/v/{$code}");

    // The organization's own address, e.g. its HR portal
    ($this->settings)(['enabled' => true, 'fields' => [], 'link' => 'https://hr.example.com/id/{code}'])->assertOk()->assertJsonPath('data.verification.fields', []);
    expect($this->getJson("/api/v1/cards/{$card['id']}")->json('meta.values.fields')['card.verify_url'])->toBe("https://hr.example.com/id/{$code}");
});

it('offers only details that may be public and checks the link address', function () {
    ($this->settings)(['enabled' => true, 'fields' => ['full_name', 'national_id', 'personal_phone'], 'link' => 'https://hr.example.com/id'])
        ->assertJsonValidationErrors(['verification.fields.1', 'verification.fields.2', 'verification.link']);
    ($this->settings)(['enabled' => true, 'fields' => [], 'link' => 'javascript:alert(1)/{code}'])->assertJsonValidationErrors('verification.link');
    ($this->settings)(['fields' => []])->assertJsonValidationErrors('verification.enabled');
});

it('shows anyone with the code whether the card is valid, with only the details the organization chose', function () {
    $holder = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => 'E-77', 'national_id' => '1990123456789']);
    $this->post("/api/v1/cardholders/{$holder->public_id}/files", ['collection' => 'photo', 'file' => UploadedFile::fake()->image('me.jpg', 300, 400)], ['Accept' => 'application/json'])->assertCreated();
    $card = $this->postJson("/api/v1/cardholders/{$holder->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new', 'expiry_date' => now()->addYear()->toDateString()])->json('data');
    $code = Card::query()->withoutGlobalScopes()->where('public_id', $card['id'])->value('verify_code');

    // Off by default: the page cannot tell an unknown code from an organization that does not verify
    $this->app['auth']->forgetGuards();
    $this->getJson("/api/v1/verify/{$code}")->assertNotFound();

    Sanctum::actingAs($this->owner);
    ($this->settings)(['enabled' => true, 'fields' => ['photo', 'employee_no', 'full_name', 'card.number'], 'note' => 'Call 123'])->assertOk();
    $this->app['auth']->forgetGuards();

    $data = $this->getJson('/api/v1/verify/'.strtoupper($code))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    expect($data)->toMatchArray([
        'status' => 'valid',
        'holder' => 'cardholder',
        'details' => [['key' => 'employee_no', 'value' => 'E-77'], ['key' => 'full_name', 'value' => 'Jane Doe'], ['key' => 'card.number', 'value' => $card['number']]],
        'note' => 'Call 123',
        'revoke_reason' => null,
    ])->and($data['organization']['name'])->toBe('Acme')
        ->and($data['photo_url'])->toStartWith('/api/v1/cardholder-files/')
        // Nothing the organization did not choose, and no internal ids
        ->and(json_encode($data))->not->toContain('1990123456789')->not->toContain($card['id']);
    $this->get($data['photo_url'])->assertOk();

    $this->getJson('/api/v1/verify/'.str_repeat('a', 24))->assertNotFound();
    $this->getJson('/api/v1/verify/short')->assertNotFound();
});

it('tells a revoked, replaced or expired card apart, with the revoke reason only if allowed', function () {
    $holder = cardholder($this->tenant);
    $issue = fn (array $extra = []) => $this->postJson("/api/v1/cardholders/{$holder->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new', ...$extra])->json('data');
    $code = fn (array $card) => Card::query()->withoutGlobalScopes()->where('public_id', $card['id'])->value('verify_code');
    ($this->settings)(['enabled' => true, 'fields' => ['full_name']])->assertOk();

    $expired = $issue(['issue_date' => '2020-01-01', 'expiry_date' => '2021-01-01']);
    $this->getJson('/api/v1/verify/'.$code($expired))->assertJsonPath('data.status', 'expired');
    $current = $issue(['reason' => 'renewal']);
    $this->getJson('/api/v1/verify/'.$code($expired))->assertJsonPath('data.status', 'replaced');
    $this->postJson("/api/v1/cards/{$current['id']}/revoke", ['note' => 'Reported lost'])->assertOk();
    $this->getJson('/api/v1/verify/'.$code($current))->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.revoke_reason', null);

    ($this->settings)(['enabled' => true, 'fields' => ['full_name'], 'show_revoke_reason' => true])->assertOk();
    $this->getJson('/api/v1/verify/'.$code($current))->assertJsonPath('data.revoke_reason', 'Reported lost');

    // A suspended organization verifies nothing
    $this->tenant->update(['status' => TenantStatus::Suspended]);
    $this->getJson('/api/v1/verify/'.$code($current))->assertNotFound();
});

it('gives connected systems the verification link with the card', function () {
    $card = ($this->issue)();
    $this->getJson("/api/v1/cards/{$card['id']}")->assertJsonPath('data.verify_url', null);

    ($this->settings)(['enabled' => true, 'fields' => []])->assertOk();
    $code = Card::query()->withoutGlobalScopes()->where('public_id', $card['id'])->value('verify_code');
    $this->getJson("/api/v1/cards/{$card['id']}")->assertJsonPath('data.verify_url', config('app.url')."/v/{$code}");
    $this->getJson('/api/v1/cards')->assertJsonPath('data.0.verify_url', config('app.url')."/v/{$code}");
});
