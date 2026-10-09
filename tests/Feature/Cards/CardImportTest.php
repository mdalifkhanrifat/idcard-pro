<?php

use App\Data\Cards\ImportCardData;
use App\Data\Cards\IssueCardData;
use App\Models\Card;
use App\Models\CardPrint;
use App\Services\Cards\CardIssueService;
use App\Services\Cards\CardNumberService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->holder = cardholder($this->tenant, ['full_name' => 'Jane Doe']);
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP', 'validity_months' => 24]);
    $this->inTenant = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant->fresh(), $callback);
    $this->import = fn (ImportCardData $data, $holder = null) => ($this->inTenant)(
        fn () => app(CardIssueService::class)->importCard(($holder ?? $this->holder)->fresh(), $this->template->fresh(), $data, $this->owner),
    );
    $this->check = fn (ImportCardData $data, $holder = null) => ($this->inTenant)(
        fn () => app(CardIssueService::class)->checkImport($holder?->fresh(), $this->template->fresh(), $data),
    );
});

it('imports a card with its number as given, the expiry from the template and the last printing', function () {
    $card = ($this->import)(new ImportCardData(
        number: 'BAF-1234',
        issueDate: CarbonImmutable::parse('2024-03-01'),
        notes: 'Old system',
        lastPrintedAt: CarbonImmutable::parse('2024-03-02 14:30:00', 'Asia/Dhaka')->utc(),
    ));

    expect($card->number)->toBe('BAF-1234')
        ->and($card->status->value)->toBe('active')
        ->and($card->reason)->toBe('new')
        ->and($card->notes)->toBe('Old system')
        ->and($card->expiry_date->toDateString())->toBe('2026-02-28')
        ->and($card->verify_code)->toHaveLength(24)
        ->and($card->layout['elements'])->not->toBeEmpty()
        ->and($card->print_count)->toBe(1)
        ->and($card->last_printed_at->toIso8601String())->toBe('2024-03-02T08:30:00+00:00');

    $print = CardPrint::query()->withoutGlobalScopes()->sole();
    expect($print->method)->toBe(CardPrint::METHOD_IMPORT)
        ->and($print->copies)->toBe(1)
        ->and($print->printed_by)->toBeNull();
});

it('leaves a card without a last printing waiting for printing, with a new number when none is given', function () {
    $card = ($this->import)(new ImportCardData(issueDate: CarbonImmutable::parse('2026-01-10')));

    expect($card->number)->toBe('EMP/26000001')
        ->and($card->fresh()->print_count)->toBe(0)
        ->and(CardPrint::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('moves the sequence past an imported number in the organization format', function () {
    ($this->import)(new ImportCardData(number: 'EMP/26000041', issueDate: CarbonImmutable::parse('2026-01-10')));

    expect(($this->inTenant)(fn () => app(CardNumberService::class)->next('EMP', CarbonImmutable::parse('2026-05-01'))))->toBe('EMP/26000042');
});

it('skips the card when the holder already has it, and replaces a different older card', function () {
    $first = ($this->import)(new ImportCardData(number: 'OLD-1', issueDate: CarbonImmutable::parse('2024-01-01')));

    // The same file again: nothing changes
    expect(($this->import)(new ImportCardData(number: 'OLD-1', issueDate: CarbonImmutable::parse('2024-01-01'))))->toBeNull()
        ->and(Card::query()->withoutGlobalScopes()->count())->toBe(1);

    $second = ($this->import)(new ImportCardData(number: 'OLD-2', issueDate: CarbonImmutable::parse('2025-01-01')));
    expect($first->fresh()->status->value)->toBe('replaced')
        ->and($first->fresh()->replaced_by_id)->toBe($second->id)
        ->and($second->status->value)->toBe('active');
});

it('reports what stops an imported card', function () {
    $other = cardholder($this->tenant, ['full_name' => 'John Roe']);
    ($this->import)(new ImportCardData(number: 'TAKEN-1', issueDate: CarbonImmutable::parse('2025-06-01'), rfCardNo: 'RF-9'), $other);
    ($this->import)(new ImportCardData(number: 'MINE-2', issueDate: CarbonImmutable::parse('2025-06-01')));

    $check = ($this->check)(new ImportCardData(
        number: 'TAKEN-1',
        issueDate: CarbonImmutable::parse('2025-01-01'),
        expiryDate: CarbonImmutable::parse('2024-12-31'),
        rfCardNo: 'RF-9',
        lastPrintedAt: CarbonImmutable::parse('2024-12-30'),
    ), $this->holder);

    expect($check['skip'])->toBeFalse()
        ->and($check['errors'])->toBe([
            __('cards.issue.expiry_before_issue'),
            __('cards.import.printed_before_issue'),
            __('cards.import.number_in_use', ['number' => 'TAKEN-1']),
            __('cards.issue.rf_in_use'),
            __('cards.import.newer_card', ['number' => 'MINE-2', 'date' => '2025-06-01']),
        ])
        ->and(($this->check)(new ImportCardData(lastPrintedAt: CarbonImmutable::now()->addDay()))['errors'])->toBe([__('cards.import.printed_in_future')]);

    expect(fn () => ($this->import)(new ImportCardData(number: 'TAKEN-1')))->toThrow(ValidationException::class);
});

it('compares the last printing with the issue date in the organization time zone', function () {
    // 1 March 00:30 in Dhaka is still 28 February in UTC
    $data = new ImportCardData(issueDate: CarbonImmutable::parse('2026-03-01'), lastPrintedAt: CarbonImmutable::parse('2026-03-01 00:30', 'Asia/Dhaka')->utc());

    expect(($this->check)($data)['errors'])->toBe([]);
});

it('still issues cards as before', function () {
    $card = ($this->inTenant)(fn () => app(CardIssueService::class)->issue($this->holder->fresh(), new IssueCardData(templateId: $this->template->public_id, reason: 'new'), $this->owner));

    expect($card->number)->toStartWith('EMP/')->and($card->active_holder_key)->toBe("c{$this->holder->id}");
});
