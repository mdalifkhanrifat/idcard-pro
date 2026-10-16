<?php

use App\Models\AppointmentClosure;
use App\Models\AppointmentLocation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/*
| Days appointments are not taken (5.3c): a holiday or a closure of one place or of every place, once or every
| year. Seen with appointments.view or settings.update, changed with settings.update, every change in the audit log.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->inTenant = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    $this->place = fn (array $attributes = []) => ($this->inTenant)(fn () => AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, ...$attributes]));
    $this->closure = fn (array $attributes = []) => ($this->inTenant)(fn () => AppointmentClosure::factory()->create(['tenant_id' => $this->tenant->id, ...$attributes]));
});

it('lets those who see appointments or may change the settings see the closures, and only the latter change them', function () {
    $closure = ($this->closure)();
    foreach (['Administrator' => [200, 403], 'Viewer' => [200, 403], 'Operator' => [200, 403]] as $role => [$see, $change]) {
        Sanctum::actingAs(tenantUser($this->tenant, $role));
        $this->getJson('/api/v1/appointment-closures')->assertStatus($see);
        $this->getJson("/api/v1/appointment-closures/{$closure->public_id}")->assertStatus($see);
        $this->postJson('/api/v1/appointment-closures', ['date_from' => '2026-12-16', 'date_to' => '2026-12-16'])->assertStatus($change);
        $this->patchJson("/api/v1/appointment-closures/{$closure->public_id}", ['reason' => 'X'])->assertStatus($change);
        $this->deleteJson("/api/v1/appointment-closures/{$closure->public_id}")->assertStatus($change);
    }

    // Another organization's closure is not there, nor its place to close
    [, $otherOwner] = tenantWithOwner('globex', 'owner@globex.test');
    Sanctum::actingAs($otherOwner);
    $this->getJson("/api/v1/appointment-closures/{$closure->public_id}")->assertNotFound();
    expect($this->getJson('/api/v1/appointment-closures')->assertOk()->json('data'))->toBe([]);
    $this->postJson('/api/v1/appointment-closures', ['location' => ($this->place)()->public_id, 'date_from' => '2026-12-16', 'date_to' => '2026-12-16'])
        ->assertUnprocessable()->assertJsonValidationErrors('location');
});

it('creates a closure of one place or of every place, changes only what is sent, and notes it in the audit log', function () {
    Sanctum::actingAs($this->owner);
    $place = ($this->place)(['name' => 'Head office', 'name_local' => 'প্রধান কার্যালয়']);

    $created = $this->postJson('/api/v1/appointment-closures', ['date_from' => '2026-12-16', 'date_to' => '2026-12-16', 'yearly' => true, 'reason' => 'Victory Day', 'reason_local' => 'বিজয় দিবস'])
        ->assertCreated()->json('data');
    expect($created)->toMatchArray(['location' => null, 'date_from' => '2026-12-16', 'date_to' => '2026-12-16', 'yearly' => true, 'reason' => 'Victory Day', 'reason_local' => 'বিজয় দিবস']);

    $oneOff = $this->postJson('/api/v1/appointment-closures', ['location' => $place->public_id, 'date_from' => '2026-11-01', 'date_to' => '2026-11-03'])->assertCreated()->json('data');
    expect($oneOff)->toMatchArray(['location' => ['id' => $place->public_id, 'name' => 'Head office', 'name_local' => 'প্রধান কার্যালয়'], 'yearly' => false, 'reason' => null]);

    // Only what is sent changes; the days are checked as they will be (the first day kept)
    $this->patchJson("/api/v1/appointment-closures/{$oneOff['id']}", ['date_to' => '2026-11-05', 'reason' => 'Repairs'])->assertOk()
        ->assertJsonPath('data.date_from', '2026-11-01')->assertJsonPath('data.date_to', '2026-11-05')->assertJsonPath('data.location.id', $place->public_id);
    $this->patchJson("/api/v1/appointment-closures/{$oneOff['id']}", ['location' => null])->assertOk()->assertJsonPath('data.location', null);
    $this->getJson("/api/v1/appointment-closures/{$oneOff['id']}")->assertOk()->assertJsonPath('data.reason', 'Repairs');

    $audit = $this->getJson('/api/v1/audit/activities?filter[log_name]=appointment_closures')->assertOk()->json('data');
    expect(array_column($audit, 'event'))->toBe(['updated', 'updated', 'created', 'created'])
        ->and($audit[3]['changes']['attributes'])->toMatchArray(['date_from' => '2026-12-16', 'yearly' => true, 'reason_local' => 'বিজয় দিবস'])
        // Only what changed
        ->and($audit[1]['changes']['attributes'])->toBe(['date_to' => '2026-11-05', 'reason' => 'Repairs'])
        ->and($audit[0]['changes']['attributes'])->toBe(['location_id' => null]);

    $this->deleteJson("/api/v1/appointment-closures/{$oneOff['id']}")->assertNoContent();
    expect(AppointmentClosure::withoutGlobalScopes()->pluck('reason')->all())->toBe(['Victory Day']);
});

it('lists from the organization\'s today on, by place, by days or with those over, yearly ones always', function () {
    Sanctum::actingAs($this->owner);
    $head = ($this->place)(['name' => 'Head office']);
    $gate = ($this->place)(['name' => 'Gate']);
    ($this->closure)(['date_from' => '2026-10-10', 'date_to' => '2026-10-16', 'reason' => 'Ended on the 16th']);
    ($this->closure)(['date_from' => '2026-10-17', 'date_to' => '2026-10-17', 'reason' => 'All places', 'location_id' => null]);
    ($this->closure)(['date_from' => '2026-11-01', 'date_to' => '2026-11-02', 'reason' => 'Head office', 'location_id' => $head->id]);
    ($this->closure)(['date_from' => '2026-12-01', 'date_to' => '2026-12-01', 'reason' => 'Gate', 'location_id' => $gate->id]);
    ($this->closure)(['date_from' => '2024-02-21', 'date_to' => '2024-02-21', 'reason' => 'Language Day', 'yearly' => true]);
    $reasons = fn (string $query = '') => array_column($this->getJson("/api/v1/appointment-closures?{$query}")->assertOk()->json('data'), 'reason');

    // 00:30 on 17 October in Dhaka (still the 16th in UTC): the one ending on the 16th is over
    Carbon::setTestNow('2026-10-16 18:30:00');
    expect($reasons())->toBe(['Language Day', 'All places', 'Head office', 'Gate'])
        ->and($reasons('filter[past]=1'))->toBe(['Language Day', 'Ended on the 16th', 'All places', 'Head office', 'Gate'])
        ->and($reasons("filter[location]={$head->public_id}"))->toBe(['Language Day', 'All places', 'Head office'])
        ->and($reasons('filter[from]=2026-10-16&filter[to]=2026-11-01'))->toBe(['Language Day', 'Ended on the 16th', 'All places', 'Head office'])
        ->and($reasons('filter[from]=2026-11-03'))->toBe(['Language Day', 'Gate'])
        ->and($reasons('filter[to]=2026-10-12'))->toBe(['Language Day', 'Ended on the 16th']);

    // 23:30 on 16 October in Dhaka: it is not over yet
    Carbon::setTestNow('2026-10-16 17:30:00');
    expect($reasons())->toContain('Ended on the 16th');

    // Pages
    $page = $this->getJson('/api/v1/appointment-closures?per_page=2')->assertOk();
    expect($page->json('meta.total'))->toBe(5)->and($page->json('data'))->toHaveCount(2);
    Carbon::setTestNow();
});

it('knows the days a closure covers, a yearly one across the new year and from its first year on', function () {
    $once = new AppointmentClosure(['date_from' => '2026-11-01', 'date_to' => '2026-11-03']);
    $yearly = new AppointmentClosure(['date_from' => '2026-12-31', 'date_to' => '2027-01-02', 'yearly' => true]);

    expect([$once->covers('2026-10-31'), $once->covers('2026-11-01'), $once->covers('2026-11-03'), $once->covers('2026-11-04'), $once->covers('2027-11-02')])
        ->toBe([false, true, true, false, false])
        ->and([$yearly->covers('2026-12-30'), $yearly->covers('2026-12-31'), $yearly->covers('2027-01-02'), $yearly->covers('2030-01-01'), $yearly->covers('2031-01-03')])
        ->toBe([false, true, true, true, false])
        // Not before its first year
        ->and($yearly->covers('2026-01-01'))->toBeFalse();
});

it('checks a closure in the reader\'s language: days in order, not too long, a place of the organization', function () {
    Sanctum::actingAs($this->owner);
    $closure = ($this->closure)(['date_from' => '2026-11-10', 'date_to' => '2026-11-12']);
    $errors = fn (array $data, string $method = 'postJson', string $url = '/api/v1/appointment-closures') => $this->{$method}($url, $data, ['Accept-Language' => 'bn'])->assertUnprocessable()->json('errors');

    expect($errors([]))->toBe(['date_from' => ['প্রথম দিন দিতে হবে।'], 'date_to' => ['শেষ দিন দিতে হবে।']])
        ->and($errors(['date_from' => '2026-11-10', 'date_to' => '2026-11-09']))->toBe(['date_to' => ['শেষ দিন প্রথম দিন বা তার পরের দিন হতে হবে।']])
        ->and($errors(['date_from' => '2026-01-01', 'date_to' => '2027-01-02']))->toBe(['date_to' => ['একটানা বন্ধ সর্বোচ্চ ৩৬৬ দিন হতে পারে।']])
        ->and($errors(['date_from' => '2026-03-01', 'date_to' => '2027-03-01', 'yearly' => true]))->toBe(['date_to' => ['প্রতি বছর ফেরা বন্ধ এক বছরের কম হতে হবে।']])
        ->and(array_keys($errors(['date_from' => '16/12/2026', 'date_to' => '2026-12-16', 'location' => 'x', 'reason' => str_repeat('a', 201)])))->toBe(['location', 'date_from', 'reason'])
        // A change is checked on the closure's own days: the last day before its first
        ->and($errors(['date_to' => '2026-11-09'], 'patchJson', "/api/v1/appointment-closures/{$closure->public_id}"))->toBe(['date_to' => ['শেষ দিন প্রথম দিন বা তার পরের দিন হতে হবে।']]);

    // A full leap year is the longest
    $this->postJson('/api/v1/appointment-closures', ['date_from' => '2028-01-01', 'date_to' => '2028-12-31'])->assertCreated();
    $this->getJson('/api/v1/appointment-closures?filter[from]=2026-11-02&filter[to]=2026-11-01', ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors', ['filter.to' => ['পর্যন্ত অবশ্যই থেকে-এর দিন বা তার পরের তারিখ হতে হবে।']]);
});

it('removes a place\'s closures with the place, and keeps those of every place', function () {
    Sanctum::actingAs($this->owner);
    $place = ($this->place)();
    ($this->closure)(['location_id' => $place->id, 'reason' => 'Own']);
    ($this->closure)(['reason' => 'Every place']);

    $this->deleteJson("/api/v1/appointment-locations/{$place->public_id}")->assertNoContent();
    expect(AppointmentClosure::withoutGlobalScopes()->pluck('reason')->all())->toBe(['Every place']);
});
