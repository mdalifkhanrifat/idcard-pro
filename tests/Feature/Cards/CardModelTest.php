<?php

use App\Models\Card;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
});

it('keeps the card number on reissue by default and lets the organization choose', function () {
    Sanctum::actingAs($this->owner);
    $settings = ['name' => 'Acme', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233'];

    $this->getJson('/api/v1/settings')->assertJsonPath('data.card_number_on_reissue', 'keep');
    $this->post('/api/v1/settings', [...$settings, 'card_number_on_reissue' => 'new'], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.card_number_on_reissue', 'new');
    $this->post('/api/v1/settings', [...$settings, 'card_number_on_reissue' => 'sometimes'], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('card_number_on_reissue');
});

it('allows one active card per holder and per number in the database', function () {
    app(TenantContext::class)->run($this->tenant, function () {
        $holder = cardholder($this->tenant);
        $first = Card::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $holder->id, 'number' => 'EMP/26000001', 'active_number_key' => 'EMP/26000001']);

        // A second active card for the same holder
        expect(fn () => Card::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $holder->id]))
            ->toThrow(UniqueConstraintViolationException::class);
        // The same number active for someone else
        expect(fn () => Card::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => cardholder($this->tenant)->id, 'number' => 'EMP/26000001', 'active_number_key' => 'EMP/26000001']))
            ->toThrow(UniqueConstraintViolationException::class);

        // Once replaced, its keys are free: the same number may be issued again (keep-number reissue)
        $first->update(['status' => 'replaced', 'active_number_key' => null, 'active_holder_key' => null]);
        $again = Card::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $holder->id, 'number' => 'EMP/26000001', 'active_number_key' => 'EMP/26000001']);

        expect($again->isActive())->toBeTrue()
            ->and(Card::query()->where('number', 'EMP/26000001')->count())->toBe(2)
            ->and($holder->cards()->first()->is($again))->toBeTrue();
    });
});
