<?php

use App\Models\Cardholder;
use App\Models\MasterDataItem;
use App\Services\Cardholders\CardholderSyncService;
use App\Support\Tenancy\TenantContext;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->sync = app(CardholderSyncService::class);
    $this->inTenant = fn (Closure $work) => app(TenantContext::class)->run($this->tenant, $work);
});

it('finds a person by employee number and updates them, or creates someone new', function () {
    cardholder($this->tenant, ['full_name' => 'Old Name', 'employee_no' => 'E-1']);

    ($this->inTenant)(function () {
        $seen = [];
        $known = $this->sync->check(['full_name' => 'New Name', 'employee_no' => 'E-1'], [], $this->sync->find('E-1'), $seen);
        $new = $this->sync->check(['full_name' => 'Someone', 'employee_no' => 'E-2'], [], $this->sync->find(' E-2 '), $seen);

        expect($known->passes())->toBeTrue()
            ->and($known->action())->toBe('updated')
            ->and($new->action())->toBe('created');

        $this->sync->save($known, $this->owner);
        $this->sync->save($new, $this->owner);
    });

    expect(Cardholder::query()->withoutGlobalScopes()->orderBy('employee_no')->pluck('full_name', 'employee_no')->all())
        ->toBe(['E-1' => 'New Name', 'E-2' => 'Someone']);
});

it('does not touch an existing person it was not given (a new person with a number already taken)', function () {
    cardholder($this->tenant, ['employee_no' => 'E-1']);

    ($this->inTenant)(function () {
        $seen = [];
        $check = $this->sync->check(['full_name' => 'New Name', 'employee_no' => 'E-1'], [], null, $seen);

        expect($check->passes())->toBeFalse()
            ->and($check->action())->toBe('created')
            ->and($check->validated)->toBe([]);
    });
});

it('names an employee number met twice in one batch in the words the source asks for', function () {
    ($this->inTenant)(function () {
        $seen = [];
        $this->sync->check(['full_name' => 'A', 'employee_no' => 'e-7'], [], null, $seen);
        $second = $this->sync->check(['full_name' => 'B', 'employee_no' => 'E-7'], [], null, $seen, 'cardholders.import.duplicate_in_file');

        expect($second->messages)->toBe([__('cardholders.import.duplicate_in_file', ['value' => 'E-7'])]);
    });
});

it('keeps the mapping errors first, and gives a field no second message', function () {
    $parent = masterItem($this->tenant, ['type' => 'department', 'name' => 'Ops']);
    masterItem($this->tenant, ['type' => 'unit', 'name' => 'Other unit']);
    $foreignUnit = MasterDataItem::query()->withoutGlobalScopes()->where('name', 'Other unit')->value('public_id');

    ($this->inTenant)(function () use ($parent, $foreignUnit) {
        $seen = [];
        $mapped = $this->sync->check(['full_name' => 'A', 'department_id' => $parent->public_id, 'unit_id' => $foreignUnit], ['unit_id' => 'Unit not found.'], null, $seen);
        $unmapped = $this->sync->check(['full_name' => 'B', 'department_id' => $parent->public_id, 'unit_id' => $foreignUnit], [], null, $seen);

        expect($mapped->messages)->toBe(['Unit not found.'])
            ->and($unmapped->messages)->toHaveCount(1)
            ->and($unmapped->messages[0])->toBe(__('cardholders.lookup_parent_mismatch', ['parent' => __('cardholders.fields.department_id')]));
    });
});

it('never saves a record that failed its check', function () {
    ($this->inTenant)(function () {
        $seen = [];
        $check = $this->sync->check(['employee_no' => 'E-1'], [], null, $seen);

        expect(fn () => $this->sync->save($check, $this->owner))->toThrow(LogicException::class);
    });

    expect(Cardholder::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('undoes only the record that failed, and says so when something broke', function () {
    ($this->inTenant)(function () {
        $failed = fn (array $messages): array => ['messages' => $messages, 'failed' => true];

        $kept = $this->sync->atomically(function (): array {
            cardholder($this->tenant, ['employee_no' => 'KEPT']);

            return ['messages' => [], 'failed' => false];
        }, $failed);
        $rolledBack = $this->sync->atomically(function (): array {
            cardholder($this->tenant, ['employee_no' => 'GONE']);

            return ['messages' => ['Bad row.'], 'failed' => false];
        }, $failed);
        $broken = $this->sync->atomically(function (): array {
            cardholder($this->tenant, ['employee_no' => 'BROKEN']);

            throw new RuntimeException('Disk full');
        }, $failed);

        expect($kept)->toBe(['messages' => [], 'failed' => false])
            ->and($rolledBack)->toBe(['messages' => ['Bad row.'], 'failed' => false])
            ->and($broken)->toBe(['messages' => [__('cardholders.import.row_failed')], 'failed' => true]);
    });

    expect(Cardholder::query()->withoutGlobalScopes()->pluck('employee_no')->all())->toBe(['KEPT']);
});
