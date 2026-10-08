<?php

use App\Models\Card;
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
    $codes = Card::query()->withoutGlobalScopes()->pluck('verify_code');

    expect($codes)->toHaveCount(2)
        ->and($codes->unique())->toHaveCount(2)
        ->and($codes->first())->toMatch('/^[a-z0-9]{24}$/');
    $this->postJson("/api/v1/cards/{$first['id']}/prints")->assertCreated();
    expect(Card::query()->withoutGlobalScopes()->where('public_id', $first['id'])->value('verify_code'))->toBe($codes->first());
});

it('keeps verification off until the organization turns it on and chooses what the page shows', function () {
    $this->getJson('/api/v1/settings')->assertJsonPath('data.verification', [
        'enabled' => false,
        'fields' => ['photo', 'full_name', 'family.name', 'family.relation', 'designation', 'card.number', 'card.expiry_date'],
        'show_revoke_reason' => false,
        'link' => null,
        'note' => null,
    ]);
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
