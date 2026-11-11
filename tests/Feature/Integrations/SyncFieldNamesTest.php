<?php

use App\Models\Cardholder;
use App\Models\User;
use App\Services\Audit\AuditChangeDescriber;
use App\Services\Integrations\IntegrationSettings;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
| 6.3f: an organization keeps its HR system's own field names (settings.integrations.sync_fields, theirs => ours),
| so the HR system sends its records as they are. Saved on the organization settings, noted in the audit log.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->save = function (mixed $names) {
        app('auth')->forgetGuards();
        Sanctum::actingAs($this->owner);

        return $this->postJson('/api/v1/settings', ['name' => 'Acme', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233', 'integrations' => ['sync_fields' => $names]]);
    };
    $this->hr = app(TenantContext::class)->run($this->tenant, function (): User {
        Role::create(['name' => 'HR sync', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id])->givePermissionTo('cardholders.sync');

        return User::factory()->integration()->create(['tenant_id' => $this->tenant->id])->assignRole('HR sync');
    });
    $this->token = $this->hr->createToken('Main', ['*'], now()->addYear())->plainTextToken;
    $this->sync = function (array $records) {
        app('auth')->forgetGuards();
        app(TenantContext::class)->set(null);

        return $this->withToken($this->token)->postJson('/api/v1/sync/cardholders', ['records' => $records]);
    };
});

it('keeps the HR field names on the organization settings, and notes each in the audit log', function () {
    ($this->save)(['emp_id' => 'employee_no', 'dept' => 'department', 'name' => 'full_name'])
        ->assertOk()
        ->assertJsonPath('data.integrations.sync_fields', ['dept' => 'department', 'emp_id' => 'employee_no', 'name' => 'full_name']);

    // Other integration settings stay as they were
    expect($this->tenant->fresh()->integrationSettings()['requests_per_minute'])->toBe((int) config('integrations.requests.per_minute'));

    ($this->save)(['emp_id' => 'employee_no'])->assertOk()->assertJsonPath('data.integrations.sync_fields', ['emp_id' => 'employee_no']);

    $logged = Activity::query()->where('log_name', 'tenant')->latest('id')->firstOrFail();
    $fields = collect(app(AuditChangeDescriber::class)->fields($logged))->keyBy('key');
    expect($fields['settings.integrations.sync_fields.dept'])->toMatchArray(['label' => 'dept — HR field name', 'old' => 'Department', 'new' => '—'])
        ->and($fields['settings.integrations.sync_fields.name'])->toMatchArray(['old' => 'Full name']);

    // An empty map clears it
    ($this->save)([])->assertOk()->assertJsonPath('data.integrations.sync_fields', []);
});

it('refuses names that are paths, fields the sync does not take, and two names for one field', function () {
    ($this->save)(['emp.id' => 'employee_no'])->assertUnprocessable()->assertJsonValidationErrors('integrations.sync_fields');
    ($this->save)(['emp_id' => 'salary'])->assertUnprocessable()
        ->assertJsonPath('errors', ['integrations.sync_fields' => ['“emp_id” stands for “salary”, which the HR sync does not take.']]);
    ($this->save)(['emp_id' => 'employee_no', 'staff_no' => 'employee_no'])->assertUnprocessable()
        ->assertJsonPath('errors', ['integrations.sync_fields' => ['Only one HR field name can stand for “employee_no” (emp_id, staff_no).']]);
    ($this->save)(['employee_no'])->assertUnprocessable()->assertJsonValidationErrors('integrations.sync_fields');

    // Whatever was saved some other way, only names are read back
    expect(IntegrationSettings::normalize(['sync_fields' => ['ok' => 'employee_no', 'a.b' => 'rank', ' x' => 'rank', 'n' => 5]])['sync_fields'])->toBe(['ok' => 'employee_no']);
});

it('reads a record by the organization\'s HR field names: the person, master data and the family', function () {
    $ops = masterItem($this->tenant, ['type' => 'department', 'name' => 'Operations']);
    $this->tenant->forceFill(['settings' => ['integrations' => ['sync_fields' => [
        'emp_id' => 'employee_no', 'name' => 'full_name', 'dept' => 'department', 'dependents' => 'family',
    ]]]])->save();

    $answer = ($this->sync)([
        ['emp_id' => 'E-1', 'name' => 'Rahim', 'dept' => 'Operations', 'dependents' => [['relation' => 'son', 'name' => 'Rafi']]],
        ['emp_id' => 'E-2', 'employee_no' => 'E-2', 'name' => 'Karim'],
        ['employee_no' => 'E-3', 'full_name' => 'Our own names still work'],
        ['emp_id' => 'X', 'employee_no' => 'E-1', 'name' => 'Both problems of one field are told'],
    ])->assertOk();

    expect($answer->json('data.records.0.employee_no'))->toBe('E-1')
        ->and($answer->json('data.records.0.result'))->toBe('created')
        ->and($answer->json('data.records.0.family.0.result'))->toBe('created')
        ->and($answer->json('data.records.1.result'))->toBe('failed')
        ->and($answer->json('data.records.1.errors.employee_no'))->toBe(['Give either emp_id (this organization\'s name for employee_no) or employee_no, not both.'])
        ->and($answer->json('data.records.2.result'))->toBe('created');

    expect($answer->json('data.records.3.errors.employee_no'))->toBe([
        'Give either emp_id (this organization\'s name for employee_no) or employee_no, not both.',
        'Employee no E-1 appears more than once in this request.',
    ]);

    $person = Cardholder::query()->withoutGlobalScopes()->where('employee_no', 'E-1')->sole();
    expect([$person->full_name, $person->department_id, $person->familyMembers()->withoutGlobalScopes()->count()])->toBe(['Rahim', $ops->id, 1]);
});
