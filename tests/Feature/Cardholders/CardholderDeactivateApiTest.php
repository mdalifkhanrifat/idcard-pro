<?php

use App\Enums\CardholderStatus;
use App\Enums\CardholderSyncKind;
use App\Models\Card;
use App\Models\Cardholder;
use App\Models\CardholderSync;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
| 6.3d: an HR system says who left, by employee number; each is marked as no longer active, never deleted.
| Real bearer tokens of an integration account whose role may only sync.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->hr = app(TenantContext::class)->run($this->tenant, function (): User {
        Role::create(['name' => 'HR sync', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id])->givePermissionTo('cardholders.sync');

        return User::factory()->integration()->create(['tenant_id' => $this->tenant->id, 'name' => 'Payroll'])->assignRole('HR sync');
    });
    $this->token = $this->hr->createToken('Main', ['*'], now()->addYear())->plainTextToken;
    $this->leave = function (array $body, array $headers = []) {
        app('auth')->forgetGuards();
        app(TenantContext::class)->set(null);

        return $this->withToken($this->token)->postJson('/api/v1/sync/cardholders/deactivate', $body, $headers);
    };
    $this->status = fn (string $employeeNo): string => Cardholder::query()->withoutGlobalScopes()->where('employee_no', $employeeNo)->firstOrFail()->status->value;
});

it('marks who left as inactive, leaves alone who already is, and names numbers no one has', function () {
    $leaving = cardholder($this->tenant, ['employee_no' => 'E-1']);
    cardholder($this->tenant, ['employee_no' => 'E-2', 'status' => 'inactive']);
    cardholder($this->tenant, ['employee_no' => 'E-3', 'status' => 'retired']);
    $card = Card::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $leaving->id, 'card_template_id' => cardTemplate($this->tenant)->id]);

    $answer = ($this->leave)(['employee_nos' => ['E-1', 'E-2', 'E-3', 'E-404', 'e-1']])->assertOk();

    expect($answer->json('data.summary'))->toBe(['total' => 5, 'deactivated' => 1, 'unchanged' => 2, 'not_found' => 1, 'failed' => 1])
        ->and($answer->json('data.dry_run'))->toBeFalse()
        ->and(collect($answer->json('data.records'))->map(fn (array $record) => [$record['index'], $record['employee_no'], $record['result'], $record['status']])->all())
        ->toBe([[0, 'E-1', 'deactivated', 'inactive'], [1, 'E-2', 'unchanged', 'inactive'], [2, 'E-3', 'unchanged', 'retired'], [3, 'E-404', 'not_found', null], [4, 'e-1', 'failed', null]])
        ->and($answer->json('data.records.0.id'))->toBe($leaving->public_id)
        ->and($answer->json('data.records.0.errors'))->toBe([])
        ->and($answer->json('data.records.3.errors.employee_no.0'))->toBe('No cardholder has employee no E-404.')
        ->and($answer->json('data.records.4.errors.employee_no.0'))->toBe('Employee no e-1 appears more than once in this request.');

    // Without a status, a retired person stays retired; nobody is deleted and no card is revoked
    expect([($this->status)('E-1'), ($this->status)('E-2'), ($this->status)('E-3')])->toBe(['inactive', 'inactive', 'retired'])
        ->and($card->fresh()->status->value)->toBe('active');

    // The audit log names the account and what changed
    $logged = Activity::query()->where('log_name', 'cardholders')->where('subject_id', $leaving->id)->where('event', 'updated')->sole();
    expect($logged->causer_id)->toBe($this->hr->id)
        ->and($logged->attribute_changes['old']['status'] ?? null)->toBe('active')
        ->and($logged->attribute_changes['attributes']['status'] ?? null)->toBe('inactive');

    // The run is kept as a deactivation
    $run = CardholderSync::withoutGlobalScopes()->sole();
    expect($answer->json('data.id'))->toBe($run->public_id)
        ->and($run->kind)->toBe(CardholderSyncKind::Deactivate)
        ->and($run->only(['user_id', 'total', 'created_count', 'updated_count', 'unchanged_count', 'not_found_count', 'failed_count']))
        ->toBe(['user_id' => $this->hr->id, 'total' => 5, 'created_count' => 0, 'updated_count' => 1, 'unchanged_count' => 2, 'not_found_count' => 1, 'failed_count' => 1])
        ->and($run->finished_at)->not->toBeNull();
});

it('applies the status given, retired or inactive, even to someone already not active', function () {
    cardholder($this->tenant, ['employee_no' => 'E-1']);
    cardholder($this->tenant, ['employee_no' => 'E-2', 'status' => 'inactive']);
    cardholder($this->tenant, ['employee_no' => 'E-3', 'status' => 'retired']);

    ($this->leave)(['employee_nos' => ['E-1', 'E-2', 'E-3'], 'status' => 'retired'])
        ->assertOk()
        ->assertJsonPath('data.summary.deactivated', 2)
        ->assertJsonPath('data.records.2.result', 'unchanged');
    expect([($this->status)('E-1'), ($this->status)('E-2'), ($this->status)('E-3')])->toBe(['retired', 'retired', 'retired']);

    ($this->leave)(['employee_nos' => ['E-3'], 'status' => 'inactive'])->assertOk()->assertJsonPath('data.records.0.result', 'deactivated');
    expect(($this->status)('E-3'))->toBe('inactive');
});

it('says what would happen in a dry run and writes nothing', function () {
    cardholder($this->tenant, ['employee_no' => 'E-1']);

    ($this->leave)(['employee_nos' => ['E-1', 'E-404'], 'dry_run' => true])
        ->assertOk()
        ->assertJsonPath('data.id', null)
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.records.0.result', 'deactivated')
        ->assertJsonPath('data.records.0.status', 'inactive')
        ->assertJsonPath('data.records.1.result', 'not_found');

    expect(($this->status)('E-1'))->toBe('active')
        ->and(CardholderSync::withoutGlobalScopes()->count())->toBe(0)
        ->and(Activity::query()->where('log_name', 'cardholders')->where('event', 'updated')->count())->toBe(0);
});

it('takes numbers sent as JSON numbers, and answers in the reader\'s language', function () {
    cardholder($this->tenant, ['employee_no' => '1001']);

    ($this->leave)(['employee_nos' => [1001, 'X-9']], ['Accept-Language' => 'bn'])
        ->assertOk()
        ->assertJsonPath('data.records.0.employee_no', '1001')
        ->assertJsonPath('data.records.0.result', 'deactivated')
        ->assertJsonPath('data.records.1.errors.employee_no.0', 'কর্মী নম্বর X-9-এর কোনো কার্ডধারী নেই।');
});

it('is done once for the same Idempotency-Key', function () {
    cardholder($this->tenant, ['employee_no' => 'E-1']);
    $first = ($this->leave)(['employee_nos' => ['E-1']], ['Idempotency-Key' => 'leavers-2026-11'])->assertOk();

    ($this->leave)(['employee_nos' => ['E-1']], ['Idempotency-Key' => 'leavers-2026-11'])
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.id', $first->json('data.id'))
        ->assertJsonPath('data.records.0.result', 'deactivated');
    expect(CardholderSync::withoutGlobalScopes()->count())->toBe(1);
});

it('needs cardholders.sync, a list of numbers, no more than allowed, and a status that is not active', function () {
    app(TenantContext::class)->run($this->tenant, fn () => $this->hr->syncRoles('Viewer'));
    ($this->leave)(['employee_nos' => ['E-1']])->assertForbidden();
    app(TenantContext::class)->run($this->tenant, fn () => $this->hr->syncRoles('HR sync'));

    config(['cardholder_sync.max_records' => 2]);
    ($this->leave)(['employee_nos' => ['E-1', 'E-2', 'E-3']])->assertUnprocessable()->assertJsonValidationErrors('employee_nos');
    ($this->leave)(['employee_nos' => []])->assertUnprocessable()->assertJsonValidationErrors('employee_nos');
    ($this->leave)(['employee_nos' => 'E-1'])->assertUnprocessable()->assertJsonCount(1, 'errors.employee_nos');
    ($this->leave)(['employee_nos' => ['a' => 'E-1']])->assertUnprocessable()->assertJsonValidationErrors('employee_nos');
    ($this->leave)(['employee_nos' => [['E-1'], '', str_repeat('9', 41)]])->assertUnprocessable()->assertJsonValidationErrors(['employee_nos.0', 'employee_nos.1', 'employee_nos.2']);
    ($this->leave)(['employee_nos' => ['E-1'], 'status' => 'active'])->assertUnprocessable()->assertJsonValidationErrors('status');
    ($this->leave)(['employee_nos' => ['E-1'], 'status' => 'gone'])->assertUnprocessable()->assertJsonValidationErrors('status');

    // A person with the right may do it too
    cardholder($this->tenant, ['employee_no' => 'E-9']);
    app('auth')->forgetGuards();
    Sanctum::actingAs($this->owner);
    $this->postJson('/api/v1/sync/cardholders/deactivate', ['employee_nos' => ['E-9']])->assertOk()->assertJsonPath('data.summary.deactivated', 1);
    expect(($this->status)('E-9'))->toBe(CardholderStatus::Inactive->value);
});
