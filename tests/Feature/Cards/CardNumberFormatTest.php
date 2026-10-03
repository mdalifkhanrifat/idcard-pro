<?php

use App\Services\Cards\CardNumberFormat;
use Carbon\CarbonImmutable;

it('renders the legacy format', function () {
    $format = new CardNumberFormat(CardNumberFormat::DEFAULT);
    $date = CarbonImmutable::create(2026, 3, 9);

    expect($format->render('emp', $date, 42))->toBe('EMP/26000042')
        ->and($format->prefix('emp', $date))->toBe('EMP/26');
});

it('supports year, month and custom separators', function () {
    expect((new CardNumberFormat('ID-{YYYY}{MM}-{SEQ:4}'))->render('X', CarbonImmutable::create(2026, 3, 9), 7))->toBe('ID-202603-0007');
});

it('rejects unusable formats', function (string $pattern) {
    expect(CardNumberFormat::problem($pattern))->not->toBeNull();
})->with(['{CODE}/{YY}', '{SEQ:3}{SEQ:3}', '{SEQ:0}', '{SEQ:11}', '{CODE}{NAME}{SEQ:4}', 'A B{SEQ:4}']);

it('accepts valid formats', function () {
    expect(CardNumberFormat::problem(CardNumberFormat::DEFAULT))->toBeNull()
        ->and(CardNumberFormat::problem('{SEQ:8}'))->toBeNull();
});
