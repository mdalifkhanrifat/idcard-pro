<?php

use App\Models\AppointmentClosure;
use App\Models\AppointmentLocation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/*
| The country's public holidays at once (5.3e): the holidays on a fixed date of the organization's country
| (config appointments.holiday_presets), offered by name and added as yearly closures of every place, those
| already there left out.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka', 'country_code' => 'bd', 'locale' => 'bn']);
    $this->inTenant = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    // The signed-in owner's organization is read again after a change
    $this->change = function (array $values): void {
        $this->tenant->update($values);
        $this->owner->unsetRelation('tenant');
    };
    $this->closures = fn () => ($this->inTenant)(fn () => AppointmentClosure::query()->orderBy('date_from')->get());
    // 00:30 on 1 January 2027 in Dhaka: still 2026 in UTC
    Carbon::setTestNow('2026-12-31 18:30:00');
});

afterEach(fn () => Carbon::setTestNow());

it('offers the country\'s holidays in the order of the year, named in the reader\'s language, marking those there', function () {
    ($this->inTenant)(fn () => AppointmentClosure::factory()->create(['tenant_id' => $this->tenant->id, 'location_id' => null, 'date_from' => '2024-12-16', 'date_to' => '2024-12-16', 'yearly' => true]));
    // Not the same: a place's own, a longer one, one only once
    $place = ($this->inTenant)(fn () => AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id]));
    ($this->inTenant)(fn () => AppointmentClosure::factory()->create(['tenant_id' => $this->tenant->id, 'location_id' => $place->id, 'date_from' => '2026-03-26', 'date_to' => '2026-03-26', 'yearly' => true]));
    ($this->inTenant)(fn () => AppointmentClosure::factory()->create(['tenant_id' => $this->tenant->id, 'location_id' => null, 'date_from' => '2026-05-01', 'date_to' => '2026-05-01', 'yearly' => false]));
    Sanctum::actingAs($this->owner);

    $presets = $this->getJson('/api/v1/appointment-closures/presets')->assertOk()->json('data');
    expect($presets['country'])->toBe('BD')
        ->and(array_column($presets['presets'], 'date'))->toBe(['02-21', '03-26', '04-14', '05-01', '12-16', '12-25'])
        ->and(array_column($presets['presets'], 'added', 'key'))->toBe(['language_martyrs_day' => false, 'independence_day' => false, 'bengali_new_year' => false, 'may_day' => false, 'victory_day' => true, 'christmas' => false])
        ->and($presets['presets'][4]['name'])->toBe('Victory Day');
    $this->getJson('/api/v1/appointment-closures/presets', ['Accept-Language' => 'bn'])->assertJsonPath('data.presets.4.name', 'বিজয় দিবস');

    // No presets for a country without any, nor without a country
    ($this->change)(['country_code' => 'NP']);
    $this->getJson('/api/v1/appointment-closures/presets')->assertJsonPath('data', ['country' => 'NP', 'presets' => []]);
    ($this->change)(['country_code' => null]);
    $this->getJson('/api/v1/appointment-closures/presets')->assertJsonPath('data', ['country' => null, 'presets' => []]);
});

it('adds the chosen ones as yearly closures of every place from the organization\'s year, leaving out those there', function () {
    ($this->inTenant)(fn () => AppointmentClosure::factory()->create(['tenant_id' => $this->tenant->id, 'location_id' => null, 'date_from' => '2025-12-16', 'date_to' => '2025-12-16', 'yearly' => true, 'reason' => 'Ours']));
    Sanctum::actingAs($this->owner);

    $response = $this->postJson('/api/v1/appointment-closures/presets', ['keys' => ['language_martyrs_day', 'victory_day', 'language_martyrs_day']])->assertCreated();
    expect($response->json('meta'))->toBe(['added' => 1, 'skipped' => 1])
        ->and($response->json('data.0'))->toMatchArray(['location' => null, 'date_from' => '2027-02-21', 'date_to' => '2027-02-21', 'yearly' => true, 'reason' => 'শহীদ দিবস ও আন্তর্জাতিক মাতৃভাষা দিবস', 'reason_local' => 'Shaheed Day and International Mother Language Day']);
    expect(($this->closures)()->pluck('reason')->all())->toBe(['Ours', 'শহীদ দিবস ও আন্তর্জাতিক মাতৃভাষা দিবস']);

    // Again: nothing more; an English organization writes its reason in English
    $this->postJson('/api/v1/appointment-closures/presets', ['keys' => ['language_martyrs_day']])->assertCreated()->assertJsonPath('meta', ['added' => 0, 'skipped' => 1]);
    ($this->change)(['locale' => 'en']);
    $this->postJson('/api/v1/appointment-closures/presets', ['keys' => ['christmas']])->assertCreated()
        ->assertJsonPath('data.0.reason', 'Christmas Day')->assertJsonPath('data.0.reason_local', 'বড়দিন');

    // Each one added is in the audit log, as any closure, by whoever added it
    $audit = $this->getJson('/api/v1/audit/activities?filter[log_name]=appointment_closures')->json('data');
    expect($audit)->toHaveCount(2)->and(array_unique(array_column(array_column($audit, 'causer'), 'email')))->toBe([$this->owner->email]);
});

it('lets only those who may change the settings add them, and checks the choice in the reader\'s language', function () {
    foreach (['Administrator', 'Viewer', 'Operator'] as $role) {
        Sanctum::actingAs(tenantUser($this->tenant, $role));
        $this->getJson('/api/v1/appointment-closures/presets')->assertOk();
        $this->postJson('/api/v1/appointment-closures/presets', ['keys' => ['christmas']])->assertForbidden();
    }

    Sanctum::actingAs($this->owner);
    $this->postJson('/api/v1/appointment-closures/presets', ['keys' => ['eid']], ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors', ['keys.0' => ['বাছাই করা ছুটি সঠিক নয়।']]);
    $this->postJson('/api/v1/appointment-closures/presets', ['keys' => []], ['Accept-Language' => 'bn'])->assertUnprocessable()->assertJsonValidationErrors('keys');
    expect(($this->closures)())->toHaveCount(0);
});

it('takes a new country from the registry alone', function () {
    config(['appointments.holiday_presets.NP' => ['new_year' => '04-14']]);
    ($this->change)(['country_code' => 'NP']);
    Sanctum::actingAs($this->owner);

    // Without a name the key shows
    $this->getJson('/api/v1/appointment-closures/presets')->assertJsonPath('data.presets', [['key' => 'new_year', 'date' => '04-14', 'name' => 'new_year', 'added' => false]]);
    $this->postJson('/api/v1/appointment-closures/presets', ['keys' => ['new_year']])->assertCreated()->assertJsonPath('data.0.date_from', '2027-04-14');
});
