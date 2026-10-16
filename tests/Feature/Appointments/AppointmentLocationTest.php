<?php

use App\Models\AppointmentLocation;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

/*
| Places people come to for an appointment (5.3b): a branch, an office or a counter, with its weekly hours, how
| many are served at once and per day, and the kinds of visit it takes. Seen with appointments.view or
| settings.update, changed with settings.update, every change in the audit log.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->location = fn (array $attributes = []) => app(TenantContext::class)->run($this->tenant, fn () => AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, ...$attributes]));
});

it('lets those who see appointments or may change the settings see the places, and only the latter change them', function () {
    $place = ($this->location)(['name' => 'Head office']);
    foreach (['Administrator' => [200, 403], 'Viewer' => [200, 403], 'Operator' => [200, 403]] as $role => [$see, $change]) {
        Sanctum::actingAs(tenantUser($this->tenant, $role));
        $this->getJson('/api/v1/appointment-locations')->assertStatus($see);
        $this->getJson("/api/v1/appointment-locations/{$place->public_id}")->assertStatus($see);
        $this->postJson('/api/v1/appointment-locations', ['name' => 'Counter 9'])->assertStatus($change);
        $this->patchJson("/api/v1/appointment-locations/{$place->public_id}", ['day_capacity' => 10])->assertStatus($change);
        $this->deleteJson("/api/v1/appointment-locations/{$place->public_id}")->assertStatus($change);
    }

    // Another organization's place is not there
    [, $otherOwner] = tenantWithOwner('globex', 'owner@globex.test');
    Sanctum::actingAs($otherOwner);
    $this->getJson("/api/v1/appointment-locations/{$place->public_id}")->assertNotFound();
    expect($this->getJson('/api/v1/appointment-locations')->assertOk()->json('data'))->toBe([]);
});

it('creates a place with the configured week, changes only what is sent, and notes it in the audit log', function () {
    Sanctum::actingAs($this->owner);

    $created = $this->postJson('/api/v1/appointment-locations', ['name' => 'Head office', 'name_local' => 'প্রধান কার্যালয়', 'address' => 'Dhaka'])->assertCreated()->json('data');
    expect($created)->toMatchArray(['name' => 'Head office', 'name_local' => 'প্রধান কার্যালয়', 'slot_capacity' => 1, 'day_capacity' => 50, 'services' => null, 'is_active' => true])
        ->and($created['hours'])->toBe(config('appointments.locations.hours'));

    // Two periods given out of order are kept in order; a weekday not sent keeps its hours, an empty one closes
    $updated = $this->patchJson("/api/v1/appointment-locations/{$created['id']}", [
        'slot_capacity' => 3,
        'services' => ['renewal', 'new_card', 'biometrics'],
        'hours' => ['mon' => [['14:00', '17:00'], ['09:00', '13:00']], 'fri' => [['09:00', '24:00']], 'thu' => []],
    ])->assertOk()->json('data');
    $day = [['09:00', '17:00']];
    expect($updated)->toMatchArray(['name' => 'Head office', 'address' => 'Dhaka', 'slot_capacity' => 3, 'services' => ['renewal', 'new_card', 'biometrics']])
        ->and($updated['hours'])->toBe(['mon' => [['09:00', '13:00'], ['14:00', '17:00']], 'tue' => $day, 'wed' => $day, 'thu' => [], 'fri' => [['09:00', '24:00']], 'sat' => [], 'sun' => $day]);

    // A new place given some days has the configured week for the rest
    $this->postJson('/api/v1/appointment-locations', ['name' => 'Gate', 'hours' => ['sat' => [['10:00', '12:00']]]])->assertCreated()
        ->assertJsonPath('data.hours.sat', [['10:00', '12:00']])->assertJsonPath('data.hours.sun', $day)->assertJsonPath('data.hours.fri', []);

    // Back to every kind of visit
    $this->patchJson("/api/v1/appointment-locations/{$created['id']}", ['services' => null])->assertOk()->assertJsonPath('data.services', null);

    $audit = $this->getJson('/api/v1/audit/activities?filter[log_name]=appointment_locations')->assertOk()->json('data');
    expect(array_column($audit, 'event'))->toBe(['updated', 'created', 'updated', 'created'])
        ->and($audit[3]['changes']['attributes']['name'])->toBe('Head office')
        ->and($audit[2]['changes']['attributes']['slot_capacity'])->toBe(3)
        // Only what changed: the services back to every kind, the hours untouched
        ->and(array_keys($audit[0]['changes']['attributes']))->toBe(['services']);

    $this->deleteJson("/api/v1/appointment-locations/{$created['id']}")->assertNoContent();
    expect(AppointmentLocation::withoutGlobalScopes()->pluck('name')->all())->toBe(['Gate']);
});

it('lists the places in their order, and finds them by name, address or whether they are on', function () {
    Sanctum::actingAs($this->owner);
    ($this->location)(['name' => 'B office', 'sort_order' => 2]);
    ($this->location)(['name' => 'A office', 'sort_order' => 2, 'address' => 'Chattogram']);
    ($this->location)(['name' => 'Gate', 'sort_order' => 1, 'is_active' => false]);
    $names = fn (string $query = '') => array_column($this->getJson("/api/v1/appointment-locations?{$query}")->assertOk()->json('data'), 'name');

    expect($names())->toBe(['Gate', 'A office', 'B office'])
        ->and($names('filter[is_active]=1'))->toBe(['A office', 'B office'])
        ->and($names('filter[is_active]=0'))->toBe(['Gate'])
        ->and($names('filter[search]=chatto'))->toBe(['A office']);
});

it('checks a place in the reader\'s language: hours, kinds of visit, limits and a unique name', function () {
    Sanctum::actingAs($this->owner);
    ($this->location)(['name' => 'Head office']);
    $errors = fn (array $data) => $this->postJson('/api/v1/appointment-locations', $data, ['Accept-Language' => 'bn'])->assertUnprocessable()->json('errors');

    expect($errors(['name' => 'Head office']))->toHaveKey('name')
        ->and($errors(['name' => 'X', 'hours' => ['mon' => [['13:00', '09:00']]]]))->toBe(['hours.mon.0' => ['সময়ের ভাগ শুরুর পরে শেষ হতে হবে।']])
        ->and($errors(['name' => 'X', 'hours' => ['tue' => [['09:00', '13:00'], ['12:00', '17:00']]]]))->toBe(['hours.tue' => ['এই দিনের দুটো সময়ের ভাগ একটার ওপর আরেকটা পড়েছে; একটা শেষ হলে তবে পরেরটা শুরু হোক।']])
        ->and(array_keys($errors(['name' => 'X', 'hours' => ['mon' => [['9:00', '25:00']], 'funday' => []]])))->toContain('hours', 'hours.mon.0.0', 'hours.mon.0.1')
        ->and(array_keys($errors(['name' => 'X', 'hours' => ['wed' => array_fill(0, 5, ['09:00', '10:00'])]])))->toContain('hours.wed')
        ->and(array_keys($errors(['name' => 'X', 'services' => ['passport'], 'slot_capacity' => 0, 'day_capacity' => 9999])))->toContain('services.0', 'slot_capacity', 'day_capacity')
        ->and(array_keys($errors(['name' => 'X', 'services' => []])))->toBe(['services']);

    // An organization's own kind of visit can be taken
    $this->putJson('/api/v1/appointments/settings', ['appointments' => ['services' => ['vehicle_pass' => ['name' => ['en' => 'Vehicle pass']]]]])->assertOk();
    $this->owner->unsetRelation('tenant');
    $this->postJson('/api/v1/appointment-locations', ['name' => 'Gate', 'services' => ['vehicle_pass']])->assertCreated();
});

it('keeps an organization within its number of places', function () {
    Sanctum::actingAs($this->owner);
    config(['appointments.locations.max' => 1]);
    ($this->location)();

    $this->postJson('/api/v1/appointment-locations', ['name' => 'Second'], ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('message', 'প্রতিষ্ঠানের সর্বোচ্চ ১টা জায়গা থাকতে পারে।');
});
