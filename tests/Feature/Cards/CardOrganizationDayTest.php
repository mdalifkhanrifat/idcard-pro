<?php

use App\Models\Signatory;
use App\Services\Cards\CardDataResolver;
use App\Services\Cards\CardNumberService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->owner->setRelation('tenant', $this->tenant);
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP']);
    Sanctum::actingAs($this->owner);
    // 1 April, 01:00 in Dhaka is still 31 March in UTC (the app's time zone)
    $this->travelTo(CarbonImmutable::parse('2026-04-01 01:00', 'Asia/Dhaka'));
});

it('issues cards on the organization\'s today, not the UTC day', function () {
    $card = $this->postJson('/api/v1/cardholders/'.cardholder($this->tenant)->public_id.'/cards', ['template' => $this->template->public_id, 'reason' => 'new'])
        ->assertCreated()
        ->json('data');

    expect($card['issue_date'])->toBe('2026-04-01');
});

it('counts a card as expired once its last day is over in the organization\'s time zone', function () {
    $issue = fn (string $expiry) => $this->postJson('/api/v1/cardholders/'.cardholder($this->tenant)->public_id.'/cards', ['template' => $this->template->public_id, 'reason' => 'new', 'issue_date' => '2025-04-01', 'expiry_date' => $expiry])->json('data');
    $yesterday = $issue('2026-03-31');
    $today = $issue('2026-04-01');

    expect($yesterday)->toMatchArray(['is_expired' => true, 'printable' => false])
        ->and($today)->toMatchArray(['is_expired' => false, 'printable' => true]);
    $this->getJson('/api/v1/cards?filter[validity]=expired')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $yesterday['id']);
    $this->getJson('/api/v1/cards?filter[validity]=expiring&filter[expiring_days]=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $today['id']);
    $this->getJson('/api/v1/cards/summary')->assertJsonPath('data.expired', 1)->assertJsonPath('data.pending', 1);
    $this->postJson("/api/v1/cards/{$yesterday['id']}/prints")->assertStatus(422);
});

it('signs with the signatory of the organization\'s today', function () {
    $authority = masterItem($this->tenant, ['type' => 'authority_type', 'name' => 'Security']);
    $this->template->update(['authority_type_id' => $authority->id]);
    app(TenantContext::class)->run($this->tenant, function () use ($authority) {
        Signatory::factory()->create(['tenant_id' => $this->tenant->id, 'authority_type_id' => $authority->id, 'name' => 'Until March', 'effective_to' => '2026-03-31']);
        Signatory::factory()->create(['tenant_id' => $this->tenant->id, 'authority_type_id' => $authority->id, 'name' => 'From April', 'effective_from' => '2026-04-01']);
    });

    $fields = app(TenantContext::class)->run($this->tenant, fn () => app(CardDataResolver::class)->fields($this->template->refresh(), cardholder($this->tenant)));

    expect($fields['signatory.name'])->toBe('From April');
});

it('numbers cards with the organization\'s year from its own midnight', function () {
    // 1 January 2027, 01:00 in Dhaka is still 2026 in UTC
    $this->travelTo(CarbonImmutable::parse('2027-01-01 01:00', 'Asia/Dhaka'));

    expect(app(TenantContext::class)->run($this->tenant, fn () => app(CardNumberService::class)->preview('EMP')))->toStartWith('EMP/27');
});
