<?php

use App\Models\Cardholder;
use App\Models\CustomField;
use App\Models\MasterDataItem;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
| 6.3b: an HR system sends its people; each is found by employee number and created or updated on its own,
| by the cardholder rules. Real bearer tokens of an integration account whose role may only sync.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->hr = app(TenantContext::class)->run($this->tenant, function (): User {
        Role::create(['name' => 'HR sync', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id])->givePermissionTo('cardholders.sync');

        return User::factory()->integration()->create(['tenant_id' => $this->tenant->id, 'name' => 'Payroll'])->assignRole('HR sync');
    });
    $this->token = $this->hr->createToken('Main', ['*'], now()->addYear())->plainTextToken;
    $this->sync = function (array $body, array $headers = []) {
        app('auth')->forgetGuards();
        app(TenantContext::class)->set(null);

        return $this->withToken($this->token)->postJson('/api/v1/sync/cardholders', $body, $headers);
    };
    $this->person = fn (string $employeeNo): Cardholder => Cardholder::query()->withoutGlobalScopes()->where('employee_no', $employeeNo)->firstOrFail();
});

it('creates, updates and leaves alone, one result per record, as the integration account', function () {
    cardholder($this->tenant, ['full_name' => 'Old Name', 'employee_no' => 'E-1']);
    cardholder($this->tenant, ['full_name' => 'Same Name', 'employee_no' => 'E-2', 'email' => 'same@example.com']);

    $answer = ($this->sync)(['records' => [
        ['employee_no' => 'E-1', 'full_name' => 'New Name'],
        ['employee_no' => 'E-2', 'email' => 'same@example.com'],
        ['employee_no' => 'E-3', 'full_name' => 'Someone New', 'date_of_birth' => '1990-04-15'],
        ['employee_no' => 'E-4'],
    ]])->assertOk();

    expect($answer->json('data.summary'))->toBe(['total' => 4, 'created' => 1, 'updated' => 1, 'unchanged' => 1, 'failed' => 1])
        ->and($answer->json('data.dry_run'))->toBeFalse()
        ->and(collect($answer->json('data.records'))->map(fn (array $record) => [$record['index'], $record['employee_no'], $record['result']])->all())
        ->toBe([[0, 'E-1', 'updated'], [1, 'E-2', 'unchanged'], [2, 'E-3', 'created'], [3, 'E-4', 'failed']])
        ->and($answer->json('data.records.0.id'))->toBe(($this->person)('E-1')->public_id)
        ->and($answer->json('data.records.2.id'))->toBe(($this->person)('E-3')->public_id)
        ->and($answer->json('data.records.0.errors'))->toBe([])
        ->and($answer->json('data.records.3.id'))->toBeNull()
        ->and(array_keys($answer->json('data.records.3.errors')))->toBe(['full_name']);

    expect(($this->person)('E-1')->full_name)->toBe('New Name')
        ->and(($this->person)('E-3')->date_of_birth->toDateString())->toBe('1990-04-15')
        ->and(Cardholder::query()->withoutGlobalScopes()->where('employee_no', 'E-4')->exists())->toBeFalse();

    // The audit log names the account that did it
    $made = Activity::query()->where('log_name', 'cardholders')->where('subject_id', ($this->person)('E-3')->id)->where('event', 'created')->firstOrFail();
    expect($made->causer_id)->toBe($this->hr->id);
});

it('keeps what a record leaves out, and clears what it sends as null', function () {
    $rank = masterItem($this->tenant, ['type' => 'rank', 'name' => 'Captain']);
    cardholder($this->tenant, ['full_name' => 'Rahim', 'employee_no' => 'E-1', 'email' => 'rahim@example.com', 'personal_phone' => '01700', 'rank_id' => $rank->id]);

    ($this->sync)(['records' => [['employee_no' => 'E-1', 'personal_phone' => null, 'rank' => null, 'posting' => 'Dhaka']]])
        ->assertOk()
        ->assertJsonPath('data.records.0.result', 'updated');

    $person = ($this->person)('E-1');
    expect([$person->full_name, $person->email, $person->personal_phone, $person->rank_id, $person->posting])
        ->toBe(['Rahim', 'rahim@example.com', null, null, 'Dhaka']);
});

it('finds master data by name, local name or code, by the id the API gives, and inside the parent', function () {
    $captain = masterItem($this->tenant, ['type' => 'rank', 'name' => 'Captain', 'name_local' => 'ক্যাপ্টেন', 'code' => 'CPT']);
    $ops = masterItem($this->tenant, ['type' => 'department', 'name' => 'Operations', 'code' => 'OPS']);
    $alpha = masterItem($this->tenant, ['type' => 'unit', 'name' => 'Alpha', 'parent_id' => $ops->id]);
    masterItem($this->tenant, ['type' => 'unit', 'name' => 'Alpha', 'parent_id' => masterItem($this->tenant, ['type' => 'department', 'name' => 'Stores'])->id]);

    $answer = ($this->sync)(['records' => [
        ['employee_no' => 'E-1', 'full_name' => 'A', 'rank' => 'captain', 'department' => 'OPS', 'unit' => 'alpha'],
        ['employee_no' => 'E-2', 'full_name' => 'B', 'rank' => ['code' => 'cpt'], 'department' => ['id' => $ops->public_id], 'unit' => ['name' => 'Alpha']],
        ['employee_no' => 'E-3', 'full_name' => 'C', 'rank' => 'ক্যাপ্টেন', 'rank_id' => null],
        ['employee_no' => 'E-4', 'full_name' => 'D', 'rank_id' => $captain->public_id],
        ['employee_no' => 'E-5', 'full_name' => 'E', 'rank' => 'Admiral', 'unit' => 'Alpha', 'department' => ['bad' => 1]],
    ]])->assertOk();

    expect(collect($answer->json('data.records'))->pluck('result')->all())->toBe(['created', 'created', 'failed', 'created', 'failed'])
        ->and(($this->person)('E-1')->only(['rank_id', 'department_id', 'unit_id']))->toBe(['rank_id' => $captain->id, 'department_id' => $ops->id, 'unit_id' => $alpha->id])
        ->and(($this->person)('E-2')->only(['rank_id', 'department_id', 'unit_id']))->toBe(['rank_id' => $captain->id, 'department_id' => $ops->id, 'unit_id' => $alpha->id])
        ->and(($this->person)('E-4')->rank_id)->toBe($captain->id)
        ->and($answer->json('data.records.2.errors'))->toBe(['rank_id' => [__('cardholders.sync.both_given', ['list' => 'rank', 'column' => 'rank_id'])]])
        ->and($answer->json('data.records.4.errors'))->toBe([
            'department_id' => [__('cardholders.sync.invalid_lookup', ['list' => 'department'])],
            'rank_id' => [__('cardholders.import.unknown_lookup', ['value' => 'Admiral', 'list' => __('cardholders.fields.rank_id')])],
            'unit_id' => [__('cardholders.import.parent_required', ['parent' => __('cardholders.fields.department_id')])],
        ]);
});

it('looks a unit up in the department the person already has when the record gives only the unit', function () {
    $ops = masterItem($this->tenant, ['type' => 'department', 'name' => 'Operations']);
    $stores = masterItem($this->tenant, ['type' => 'department', 'name' => 'Stores']);
    $alpha = masterItem($this->tenant, ['type' => 'unit', 'name' => 'Alpha', 'parent_id' => $ops->id]);
    $storesAlpha = masterItem($this->tenant, ['type' => 'unit', 'name' => 'Alpha', 'parent_id' => $stores->id]);
    cardholder($this->tenant, ['employee_no' => 'E-1', 'department_id' => $ops->id]);
    cardholder($this->tenant, ['employee_no' => 'E-2', 'department_id' => $ops->id]);

    $answer = ($this->sync)(['records' => [
        ['employee_no' => 'E-1', 'unit' => 'Alpha'],
        ['employee_no' => 'E-2', 'department' => 'Stores', 'unit' => 'Alpha'],
    ]])->assertOk();

    expect(collect($answer->json('data.records'))->pluck('result')->all())->toBe(['updated', 'updated'])
        ->and(($this->person)('E-1')->unit_id)->toBe($alpha->id)
        ->and(($this->person)('E-2')->only(['department_id', 'unit_id']))->toBe(['department_id' => $stores->id, 'unit_id' => $storesAlpha->id]);

    // Clearing the department leaves no parent to look the unit up in
    ($this->sync)(['records' => [['employee_no' => 'E-1', 'department' => null, 'unit' => 'Alpha']]])
        ->assertJsonPath('data.records.0.errors.unit_id.0', __('cardholders.import.parent_required', ['parent' => __('cardholders.fields.department_id')]));
});

it('creates missing master data only when asked, and only for a record that is saved', function () {
    $answer = ($this->sync)(['create_missing_lookups' => true, 'records' => [
        ['employee_no' => 'E-1', 'full_name' => 'A', 'rank' => 'Major'],
        ['employee_no' => 'E-2', 'rank' => 'Colonel'],
    ]])->assertOk();

    expect(collect($answer->json('data.records'))->pluck('result')->all())->toBe(['created', 'failed'])
        ->and($answer->json('data.lookups_created'))->toBe(['rank: Major'])
        ->and(MasterDataItem::query()->withoutGlobalScopes()->where('type', 'rank')->pluck('name')->all())->toBe(['Major']);

    ($this->sync)(['records' => [['employee_no' => 'E-3', 'full_name' => 'C', 'rank' => 'General']]])
        ->assertJsonPath('data.records.0.result', 'failed');
    expect(MasterDataItem::query()->withoutGlobalScopes()->where('name', 'General')->exists())->toBeFalse();
});

it('says what would happen in a dry run and writes nothing', function () {
    cardholder($this->tenant, ['full_name' => 'Old Name', 'employee_no' => 'E-1']);

    $answer = ($this->sync)(['dry_run' => true, 'create_missing_lookups' => true, 'records' => [
        ['employee_no' => 'E-1', 'full_name' => 'New Name'],
        ['employee_no' => 'E-2', 'full_name' => 'Someone', 'rank' => 'Major'],
        ['employee_no' => 'E-3'],
    ]])->assertOk();

    expect($answer->json('data.dry_run'))->toBeTrue()
        ->and(collect($answer->json('data.records'))->pluck('result')->all())->toBe(['updated', 'created', 'failed'])
        ->and($answer->json('data.records.1.id'))->toBeNull()
        ->and($answer->json('data.lookups_created'))->toBe(['rank: Major'])
        ->and(($this->person)('E-1')->full_name)->toBe('Old Name')
        ->and(Cardholder::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(MasterDataItem::query()->withoutGlobalScopes()->where('type', 'rank')->exists())->toBeFalse();
});

it('names every problem by its field: unknown fields, no employee number, the same one twice, the rules', function () {
    app(TenantContext::class)->run($this->tenant, fn () => CustomField::factory()->create(['tenant_id' => $this->tenant->id, 'entity' => 'cardholder', 'key' => 'shoe_size', 'label' => 'Shoe size', 'type' => 'number']));

    $answer = ($this->sync)(['records' => [
        ['employee_no' => 'E-1', 'full_name' => 'A', 'emp_name' => 'x'],
        ['full_name' => 'B'],
        ['employee_no' => 'E-2', 'full_name' => 'C', 'email' => 'not-an-email', 'custom_fields' => ['shoe_size' => 'big']],
        ['employee_no' => 'e-2', 'full_name' => 'D'],
        ['employee_no' => 1234, 'full_name' => 'E', 'custom_fields' => ['shoe_size' => 42]],
    ]])->assertOk();

    expect(collect($answer->json('data.records'))->pluck('result')->all())->toBe(['failed', 'failed', 'failed', 'failed', 'created'])
        ->and($answer->json('data.records.0.errors'))->toBe(['emp_name' => [__('cardholders.sync.unknown_field', ['field' => 'emp_name'])]])
        ->and($answer->json('data.records.1.errors'))->toBe(['employee_no' => [__('cardholders.sync.employee_no_required')]])
        ->and(array_keys($answer->json('data.records.2.errors')))->toBe(['email', 'custom_fields.shoe_size'])
        ->and($answer->json('data.records.3.errors'))->toBe(['employee_no' => [__('cardholders.sync.duplicate_in_batch', ['value' => 'e-2'])]])
        ->and($answer->json('data.records.4.employee_no'))->toBe(1234)
        ->and(($this->person)('1234')->custom_fields)->toBe(['shoe_size' => 42]);
});

it('takes a cardholder back the way the API gave it', function () {
    Sanctum::actingAs($this->owner);
    $rank = masterItem($this->tenant, ['type' => 'rank', 'name' => 'Captain']);
    $id = $this->postJson('/api/v1/cardholders', ['full_name' => 'Rahim', 'employee_no' => 'E-1', 'rank_id' => $rank->public_id])->assertCreated()->json('data.id');
    $given = $this->getJson("/api/v1/cardholders/{$id}")->json('data');

    ($this->sync)(['records' => [[...$given, 'full_name' => 'Rahim Uddin']]])
        ->assertOk()
        ->assertJsonPath('data.records.0.result', 'updated')
        ->assertJsonPath('data.records.0.errors', []);
    expect(($this->person)('E-1')->only(['full_name', 'rank_id']))->toBe(['full_name' => 'Rahim Uddin', 'rank_id' => $rank->id]);
});

it('answers in the reader\'s language', function () {
    ($this->sync)(['records' => [['full_name' => 'A']]], ['Accept-Language' => 'bn'])
        ->assertOk()
        ->assertJsonPath('data.records.0.errors.employee_no.0', 'প্রতিটা রেকর্ডে employee_no লাগবে: মানুষ এটা দিয়েই চেনা হয়।');
});

it('needs cardholders.sync, a list of records and no more than allowed', function () {
    app(TenantContext::class)->run($this->tenant, fn () => $this->hr->syncRoles('Viewer'));
    ($this->sync)(['records' => [['employee_no' => 'E-1', 'full_name' => 'A']]])->assertForbidden();
    app(TenantContext::class)->run($this->tenant, fn () => $this->hr->syncRoles('HR sync'));

    config(['cardholder_sync.max_records' => 2]);
    ($this->sync)(['records' => [['employee_no' => 'E-1'], ['employee_no' => 'E-2'], ['employee_no' => 'E-3']]])->assertUnprocessable()->assertJsonValidationErrors('records');
    ($this->sync)(['records' => []])->assertUnprocessable()->assertJsonValidationErrors('records');
    ($this->sync)(['records' => ['a' => ['employee_no' => 'E-1']]])->assertUnprocessable()->assertJsonValidationErrors('records');
    ($this->sync)(['records' => ['not a record']])->assertUnprocessable()->assertJsonValidationErrors('records.0');

    // A person with the right may sync too (e.g. trying it out)
    app('auth')->forgetGuards();
    Sanctum::actingAs($this->owner);
    $this->postJson('/api/v1/sync/cardholders', ['records' => [['employee_no' => 'E-9', 'full_name' => 'Z']]])->assertOk()->assertJsonPath('data.summary.created', 1);
});
