<?php

use App\Services\Cards\CardNumberService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
});

function nextNumber($tenant, string $code, ?CarbonImmutable $date = null): string
{
    return app(TenantContext::class)->run($tenant->fresh(), fn () => app(CardNumberService::class)->next($code, $date));
}

it('issues increasing numbers that restart per code and year', function () {
    $y26 = CarbonImmutable::create(2026, 5, 1);

    expect(nextNumber($this->tenant, 'EMP', $y26))->toBe('EMP/26000001')
        ->and(nextNumber($this->tenant, 'EMP', $y26))->toBe('EMP/26000002')
        ->and(nextNumber($this->tenant, 'VIS', $y26))->toBe('VIS/26000001')
        ->and(nextNumber($this->tenant, 'EMP', CarbonImmutable::create(2027, 1, 1)))->toBe('EMP/27000001');
});

it('keeps counters per organization', function () {
    [$other] = tenantWithOwner('beta', 'b@example.com');
    $date = CarbonImmutable::create(2026, 5, 1);

    nextNumber($this->tenant, 'EMP', $date);
    nextNumber($this->tenant, 'EMP', $date);

    expect(nextNumber($other, 'EMP', $date))->toBe('EMP/26000001');
});

it('saves the format in settings and previews without reserving numbers', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/settings')->assertJsonPath('data.card_number_format', '{CODE}/{YY}{SEQ:6}');

    $this->post('/api/v1/settings', [
        'name' => 'Acme', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233', 'card_number_format' => '{CODE}-{YYYY}-{SEQ:4}',
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.card_number_format', '{CODE}-{YYYY}-{SEQ:4}');

    $year = now()->format('Y');
    $this->getJson('/api/v1/settings/card-number-preview?code=VIS')->assertJsonPath('data.number', "VIS-{$year}-0001");
    $this->getJson('/api/v1/settings/card-number-preview?code=VIS')->assertJsonPath('data.number', "VIS-{$year}-0001");
    $this->getJson('/api/v1/settings/card-number-preview?code=VIS&format='.urlencode('{SEQ:3}'))->assertJsonPath('data.number', '001');

    expect(nextNumber($this->tenant, 'VIS'))->toBe("VIS-{$year}-0001");
    $this->getJson('/api/v1/settings/card-number-preview?code=VIS')->assertJsonPath('data.number', "VIS-{$year}-0002");
});

it('rejects invalid formats', function () {
    Sanctum::actingAs($this->owner);

    $this->post('/api/v1/settings', ['name' => 'Acme', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233', 'card_number_format' => '{CODE}/{YY}'], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('card_number_format');
    $this->getJson('/api/v1/settings/card-number-preview?format='.urlencode('{NAME}{SEQ:2}'))->assertJsonValidationErrors('format');
});

it('moves the counter past a number given from outside, never back', function () {
    $date = CarbonImmutable::create(2026, 5, 1);
    $claim = fn (string $number) => app(TenantContext::class)->run($this->tenant->fresh(), fn () => app(CardNumberService::class)->claim($number, 'EMP'));

    $claim('EMP/26000041');
    expect(nextNumber($this->tenant, 'EMP', $date))->toBe('EMP/26000042');

    // Lower numbers, other years and numbers outside the format leave this counter alone
    $claim('EMP/26000007');
    $claim('EMP/25000500');
    $claim('BAF-99999');
    expect(nextNumber($this->tenant, 'EMP', $date))->toBe('EMP/26000043')
        ->and(nextNumber($this->tenant, 'EMP', CarbonImmutable::create(2025, 5, 1)))->toBe('EMP/25000501');
});
