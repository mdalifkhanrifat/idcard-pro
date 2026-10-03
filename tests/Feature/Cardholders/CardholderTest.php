<?php

use App\Models\Cardholder;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
});

function cardholder(Tenant $tenant, array $attributes = []): Cardholder
{
    return app(TenantContext::class)->run($tenant, fn () => Cardholder::factory()->create([...$attributes, 'tenant_id' => $tenant->id]));
}

it('creates, shows, updates and deletes a cardholder', function () {
    Sanctum::actingAs($this->owner);
    $department = masterItem($this->tenant, ['type' => 'department', 'name' => 'Engineering']);
    $unit = masterItem($this->tenant, ['type' => 'unit', 'name' => 'Workshop', 'parent_id' => $department->id]);
    $bloodGroup = masterItem($this->tenant, ['type' => 'blood_group', 'name' => 'Z+']);

    $id = $this->postJson('/api/v1/cardholders', [
        'full_name' => 'Rahim Uddin',
        'full_name_local' => 'রহিম উদ্দিন',
        'date_of_birth' => '1990-05-01',
        'gender' => 'male',
        'employee_no' => 'E-1001',
        'joining_date' => '2015-01-10',
        'department_id' => $department->public_id,
        'unit_id' => $unit->public_id,
        'blood_group_id' => $bloodGroup->public_id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.department.name', 'Engineering')
        ->assertJsonPath('data.unit.name', 'Workshop')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.employment_type', 'staff')
        ->assertJsonPath('data.created_by.name', 'Owner')
        ->json('data.id');

    $this->getJson("/api/v1/cardholders/{$id}")
        ->assertOk()
        ->assertJsonPath('data.full_name_local', 'রহিম উদ্দিন')
        ->assertJsonPath('data.blood_group.name', 'Z+')
        ->assertJsonMissingPath('data.tenant_id');

    $this->putJson("/api/v1/cardholders/{$id}", ['full_name' => 'Rahim Uddin Khan', 'unit_id' => null, 'status' => 'retired'])
        ->assertOk()
        ->assertJsonPath('data.status', 'retired')
        ->assertJsonPath('data.unit', null)
        ->assertJsonPath('data.department.name', 'Engineering');

    $this->deleteJson("/api/v1/cardholders/{$id}")->assertNoContent();
    expect(Cardholder::where('public_id', $id)->exists())->toBeFalse();
});

it('only accepts master data of the right type from the same organization', function () {
    Sanctum::actingAs($this->owner);
    $rank = masterItem($this->tenant, ['type' => 'rank', 'name' => 'Captain']);
    [$otherTenant] = tenantWithOwner('beta', 'b@example.com');
    $foreignDepartment = masterItem($otherTenant, ['type' => 'department', 'name' => 'Foreign']);

    $this->postJson('/api/v1/cardholders', ['full_name' => 'X', 'department_id' => $rank->public_id])
        ->assertJsonValidationErrors('department_id');
    $this->postJson('/api/v1/cardholders', ['full_name' => 'X', 'department_id' => $foreignDepartment->public_id])
        ->assertJsonValidationErrors('department_id');
});

it('requires a unit to belong to the chosen department', function () {
    Sanctum::actingAs($this->owner);
    $hr = masterItem($this->tenant, ['type' => 'department', 'name' => 'HR']);
    $it = masterItem($this->tenant, ['type' => 'department', 'name' => 'IT']);
    $helpdesk = masterItem($this->tenant, ['type' => 'unit', 'name' => 'Helpdesk', 'parent_id' => $it->id]);

    $this->postJson('/api/v1/cardholders', ['full_name' => 'X', 'department_id' => $hr->public_id, 'unit_id' => $helpdesk->public_id])
        ->assertJsonValidationErrors('unit_id');
    $this->postJson('/api/v1/cardholders', ['full_name' => 'X', 'unit_id' => $helpdesk->public_id])
        ->assertJsonValidationErrors('unit_id');

    $id = $this->postJson('/api/v1/cardholders', ['full_name' => 'X', 'department_id' => $it->public_id, 'unit_id' => $helpdesk->public_id])
        ->assertCreated()
        ->json('data.id');

    // An update that leaves the department out is checked against the stored one.
    $this->putJson("/api/v1/cardholders/{$id}", ['full_name' => 'X', 'unit_id' => $helpdesk->public_id])->assertOk();
});

it('keeps employee and national id numbers unique within an organization', function () {
    Sanctum::actingAs($this->owner);
    $existing = cardholder($this->tenant, ['employee_no' => 'E-1', 'national_id' => '1990123']);
    [$otherTenant] = tenantWithOwner('beta', 'b@example.com');
    cardholder($otherTenant, ['employee_no' => 'E-2']);

    $this->postJson('/api/v1/cardholders', ['full_name' => 'X', 'employee_no' => 'E-1'])->assertJsonValidationErrors('employee_no');
    $this->postJson('/api/v1/cardholders', ['full_name' => 'X', 'national_id' => '1990123'])->assertJsonValidationErrors('national_id');
    $this->postJson('/api/v1/cardholders', ['full_name' => 'X', 'employee_no' => 'E-2'])->assertCreated();
    $this->putJson("/api/v1/cardholders/{$existing->public_id}", ['full_name' => 'Same', 'employee_no' => 'E-1'])->assertOk();
});

it('validates input', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/v1/cardholders', [
        'gender' => 'robot',
        'date_of_birth' => '2999-01-01',
        'joining_date' => '2020-01-01',
        'retirement_date' => '2019-01-01',
        'email' => 'not-an-email',
        'status' => 'gone',
    ])->assertJsonValidationErrors(['full_name', 'gender', 'date_of_birth', 'retirement_date', 'email', 'status']);
});

it('filters and sorts the list', function () {
    Sanctum::actingAs($this->owner);
    $finance = masterItem($this->tenant, ['type' => 'department', 'name' => 'Finance']);
    $captain = masterItem($this->tenant, ['type' => 'rank', 'name' => 'Captain']);
    cardholder($this->tenant, ['full_name' => 'Anika Rahman', 'employee_no' => 'F-1', 'department_id' => $finance->id, 'rank_id' => $captain->id, 'joining_date' => '2010-03-01']);
    cardholder($this->tenant, ['full_name' => 'Babul Mia', 'employee_no' => 'F-2', 'department_id' => $finance->id, 'joining_date' => '2020-03-01', 'personal_phone' => '01711000000']);
    cardholder($this->tenant, ['full_name' => 'Chandra Das', 'employee_no' => 'V-9', 'employment_type' => 'visitor', 'status' => 'inactive', 'joining_date' => '2022-01-01']);

    $names = fn (string $query) => collect($this->getJson("/api/v1/cardholders?{$query}")->assertOk()->json('data'))->pluck('full_name')->all();

    expect($names('filter[search]=babul'))->toBe(['Babul Mia'])
        ->and($names('filter[search]=017110'))->toBe(['Babul Mia'])
        ->and($names('filter[search]=F-&sort=full_name'))->toBe(['Anika Rahman', 'Babul Mia'])
        ->and($names("filter[department]={$finance->public_id}&sort=-full_name"))->toBe(['Babul Mia', 'Anika Rahman'])
        ->and($names("filter[rank]={$captain->public_id}"))->toBe(['Anika Rahman'])
        ->and($names('filter[status]=inactive'))->toBe(['Chandra Das'])
        ->and($names('filter[employment_type]=visitor'))->toBe(['Chandra Das'])
        ->and($names('filter[joined_from]=2015-01-01&filter[joined_to]=2021-12-31'))->toBe(['Babul Mia']);

    $this->getJson('/api/v1/cardholders?filter[unknown]=1')->assertJsonValidationErrors('filter');
    $this->getJson('/api/v1/cardholders?sort=national_id')->assertJsonValidationErrors('sort');
});

it('keeps organizations isolated', function () {
    [$otherTenant] = tenantWithOwner('beta', 'b@example.com');
    $foreign = cardholder($otherTenant, ['full_name' => 'Hidden Person']);
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/cardholders')->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/cardholders/{$foreign->public_id}")->assertNotFound();
    $this->putJson("/api/v1/cardholders/{$foreign->public_id}", ['full_name' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/cardholders/{$foreign->public_id}")->assertNotFound();
});

it('enforces permissions', function () {
    $existing = cardholder($this->tenant);

    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    $this->getJson('/api/v1/cardholders')->assertOk();
    $this->postJson('/api/v1/cardholders', ['full_name' => 'X'])->assertForbidden();

    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));
    $this->postJson('/api/v1/cardholders', ['full_name' => 'X'])->assertCreated();
    $this->putJson("/api/v1/cardholders/{$existing->public_id}", ['full_name' => 'Y'])->assertOk();
    $this->deleteJson("/api/v1/cardholders/{$existing->public_id}")->assertForbidden();
});

it('keeps personal identifiers out of the audit log', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/v1/cardholders', ['full_name' => 'Audit Person', 'national_id' => '555666777', 'personal_phone' => '01900000000'])
        ->assertCreated();

    $changes = json_encode(Activity::query()->where('log_name', 'cardholders')->latest('id')->firstOrFail()->attribute_changes);

    expect($changes)->toContain('Audit Person')
        ->not->toContain('555666777')
        ->not->toContain('01900000000');
});
