<?php

use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
| 6.3h: every field of a person and of a family member is audited — a posting changed by the HR sync left no trace
| at all — while identifiers and contact details (config audit.private) are noted as changed, their values never
| written to the audit table.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->hr = app(TenantContext::class)->run($this->tenant, function (): User {
        Role::create(['name' => 'HR sync', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id])->givePermissionTo('cardholders.sync');

        return User::factory()->integration()->create(['tenant_id' => $this->tenant->id, 'name' => 'HR'])->assignRole('HR sync');
    });
    $this->as = function (User $user) {
        app('auth')->forgetGuards();
        app(TenantContext::class)->set(null);
        Sanctum::actingAs($user);
    };
    $this->rows = fn (string $log, string $event) => Activity::query()->where('log_name', $log)->where('event', $event)->orderBy('id')->get();
});

it('audits a change to any field of a person, by whoever made it', function () {
    $holder = cardholder($this->tenant, ['employee_no' => 'E-1', 'posting' => 'Chattogram']);

    ($this->as)($this->hr);
    $this->postJson('/api/v1/sync/cardholders', ['records' => [['employee_no' => 'E-1', 'posting' => 'Dhaka', 'company_name' => 'Acme Ltd']]])
        ->assertOk()->assertJsonPath('data.records.0.result', 'updated');

    $row = ($this->rows)('cardholders', 'updated')->sole();
    expect($row->subject_id)->toBe($holder->id)
        ->and($row->causer_id)->toBe($this->hr->id)
        ->and($row->attribute_changes->toArray())->toBe([
            'attributes' => ['posting' => 'Dhaka', 'company_name' => 'Acme Ltd'],
            'old' => ['posting' => 'Chattogram', 'company_name' => null],
        ]);
});

it('notes a person\'s private fields as changed, never writing their values', function () {
    $holder = cardholder($this->tenant, ['employee_no' => 'E-1', 'national_id' => '1990123456789', 'personal_phone' => '01711000000']);

    // Created: only the fields that hold something; a private one given is noted without its value
    $created = ($this->rows)('cardholders', 'created')->sole()->attribute_changes['attributes'];
    expect($created)->toHaveKeys(['full_name', 'employee_no', 'national_id', 'personal_phone'])
        ->and($created['employee_no'])->toBe('E-1')
        ->and($created['national_id'])->toBeNull()
        ->and($created)->not->toHaveKeys(['passport_no', 'posting', 'company_name']);

    ($this->as)($this->hr);
    $this->postJson('/api/v1/sync/cardholders', ['records' => [['employee_no' => 'E-1', 'national_id' => '1990987654321', 'personal_phone' => null, 'custom_fields' => []]]])
        ->assertOk()->assertJsonPath('data.records.0.result', 'updated');

    $updated = ($this->rows)('cardholders', 'updated')->sole();
    expect($updated->attribute_changes->toArray())->toBe([
        'attributes' => ['national_id' => null, 'personal_phone' => null],
        'old' => ['national_id' => null, 'personal_phone' => null],
    ])->and(DB::table('activity_log')->where(fn ($q) => $q
        ->where('attribute_changes', 'like', '%1990%')->orWhere('attribute_changes', 'like', '%01711%'))->exists())->toBeFalse()
        ->and($holder->fresh()->national_id)->toBe('1990987654321');

    // The audit page reads which field changed, and "(hidden)" for its values
    ($this->as)($this->owner);
    $this->getJson('/api/v1/audit/activities?filter[log_name]=cardholders&filter[event]=updated')
        ->assertOk()
        ->assertJsonPath('data.0.fields', [
            ['key' => 'national_id', 'label' => 'National ID', 'old' => '(hidden)', 'new' => '(hidden)'],
            ['key' => 'personal_phone', 'label' => 'Phone', 'old' => '(hidden)', 'new' => '(hidden)'],
        ]);
});

it('audits every field of a family member, the private ones without their values', function () {
    $holder = cardholder($this->tenant, ['employee_no' => 'E-1']);
    $member = app(TenantContext::class)->run($this->tenant, fn (): FamilyMember => $holder->familyMembers()->create([
        'external_id' => 'F-1', 'relation' => 'spouse', 'name' => 'Salma', 'phone' => '01811000000', 'sort_order' => 1,
    ]));

    ($this->as)($this->hr);
    $this->postJson('/api/v1/sync/cardholders', ['records' => [['employee_no' => 'E-1', 'family' => [
        ['external_id' => 'F-1', 'name_local' => 'সালমা', 'gender' => 'female', 'phone' => '01811999999'],
    ]]]])->assertOk()->assertJsonPath('data.records.0.family.0.result', 'updated');

    $row = ($this->rows)('family_members', 'updated')->sole();
    expect($row->subject_id)->toBe($member->id)
        ->and($row->causer_id)->toBe($this->hr->id)
        ->and($row->attribute_changes['attributes'])->toBe(['name_local' => 'সালমা', 'gender' => 'female', 'phone' => null])
        ->and($row->attribute_changes['old'])->toBe(['name_local' => null, 'gender' => null, 'phone' => null]);
    expect(Cardholder::query()->count())->toBe(1);
});
