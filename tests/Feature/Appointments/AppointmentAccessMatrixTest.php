<?php

use App\Models\AppointmentClosure;
use App\Models\AppointmentLocation;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;

/*
| Appointments end check (5.3f): who may do what across every appointments API — the settings, places,
| closures, the country's holidays and free times — for each default role, a user without appointments and
| another organization; and every JSON API takes back what it answered, changing nothing.
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['country_code' => 'BD']);
    $this->inTenant = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    $this->place = ($this->inTenant)(fn () => AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Head office']));
    $this->closure = ($this->inTenant)(fn () => AppointmentClosure::factory()->create(['tenant_id' => $this->tenant->id, 'location_id' => $this->place->id]));
    $this->owner->unsetRelation('tenant');
});

/**
 * Every appointments call: [method, path, body], fresh records for those that change or remove one.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function appointmentCalls(object $test): array
{
    $place = $test->place->public_id;
    $closure = $test->closure->public_id;
    $spare = fn (): string => ($test->inTenant)(fn () => AppointmentLocation::factory()->create(['tenant_id' => $test->tenant->id]))->public_id;
    $spareClosure = fn (): string => ($test->inTenant)(fn () => AppointmentClosure::factory()->create(['tenant_id' => $test->tenant->id]))->public_id;

    return [
        'see settings' => ['GET', '/api/v1/appointments/settings', []],
        'list places' => ['GET', '/api/v1/appointment-locations', []],
        'see a place' => ['GET', "/api/v1/appointment-locations/{$place}", []],
        'free times' => ['GET', "/api/v1/appointment-locations/{$place}/availability?service=new_card&preview=1", []],
        'list closures' => ['GET', '/api/v1/appointment-closures', []],
        'see a closure' => ['GET', "/api/v1/appointment-closures/{$closure}", []],
        'country holidays' => ['GET', '/api/v1/appointment-closures/presets', []],
        'save settings' => ['PUT', '/api/v1/appointments/settings', ['appointments' => ['days_ahead' => 20]]],
        'add a place' => ['POST', '/api/v1/appointment-locations', ['name' => 'Place '.uniqid()]],
        'change a place' => ['PATCH', "/api/v1/appointment-locations/{$place}", ['address' => 'Road 1']],
        'delete a place' => ['DELETE', '/api/v1/appointment-locations/'.$spare(), []],
        'add a closure' => ['POST', '/api/v1/appointment-closures', ['date_from' => '2026-12-16', 'date_to' => '2026-12-16']],
        'change a closure' => ['PATCH', "/api/v1/appointment-closures/{$closure}", ['reason' => 'Audit']],
        'delete a closure' => ['DELETE', '/api/v1/appointment-closures/'.$spareClosure(), []],
        'add holidays' => ['POST', '/api/v1/appointment-closures/presets', ['keys' => ['victory_day']]],
    ];
}

it('lets every role see appointments, only those who may change the settings change them, and nobody else anything', function () {
    $sees = fn (string $call) => in_array(explode(' ', $call)[0], ['see', 'list', 'free', 'country'], true);
    $users = [
        'Owner' => $this->owner,
        'Administrator' => tenantUser($this->tenant, 'Administrator'),
        'Viewer' => tenantUser($this->tenant, 'Viewer'),
        'Operator' => tenantUser($this->tenant, 'Operator'),
        'without appointments' => ($this->inTenant)(fn () => User::factory()->create(['tenant_id' => $this->tenant->id])),
    ];
    $outcomes = [];
    foreach ($users as $who => $user) {
        Sanctum::actingAs($user);
        foreach (appointmentCalls($this) as $call => [$method, $path, $body]) {
            $status = $this->json($method, $path, $body)->status();
            $allowed = match ($who) {
                'Owner' => true,
                'without appointments' => false,
                default => $sees($call),
            };
            $outcomes["{$who}: {$call}"] = $allowed ? ($status < 300) : ($status === 403);
            if ($allowed && $status >= 300) {
                $outcomes["{$who}: {$call}"] = "unexpected {$status}";
            }
        }
    }

    expect(array_filter($outcomes, fn ($ok) => $ok !== true))->toBe([]);
});

it('keeps another organization\'s places and closures out of reach', function () {
    [, $otherOwner] = tenantWithOwner('globex', 'owner@globex.test');
    Sanctum::actingAs($otherOwner);

    foreach (appointmentCalls($this) as $call => [$method, $path, $body]) {
        if (! str_contains($path, $this->place->public_id) && ! str_contains($path, $this->closure->public_id)) {
            continue;
        }
        expect($this->json($method, $path, $body)->status())->toBe(404, $call);
    }
    // Its own lists hold nothing of this organization's, and a closure cannot name this organization's place
    expect($this->getJson('/api/v1/appointment-locations')->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/appointment-closures?filter[past]=1')->json('data'))->toBe([]);
    $this->postJson('/api/v1/appointment-closures', ['location' => $this->place->public_id, 'date_from' => '2026-12-16', 'date_to' => '2026-12-16'])->assertUnprocessable()->assertJsonValidationErrors('location');
    expect($this->place->fresh()->address)->toBeNull()->and($this->closure->fresh()->reason)->toBe($this->closure->reason);
});

it('takes back a place and a closure as it answered them, changing nothing', function () {
    Sanctum::actingAs($this->owner);
    $this->patchJson("/api/v1/appointment-locations/{$this->place->public_id}", ['services' => ['renewal'], 'hours' => ['sat' => [['09:00', '13:00'], ['14:00', '24:00']]]])->assertOk();
    $this->patchJson("/api/v1/appointment-closures/{$this->closure->public_id}", ['reason' => 'Audit', 'reason_local' => 'নিরীক্ষা'])->assertOk();
    $audit = fn () => ($this->inTenant)(fn () => DB::table('activity_log')->count());
    $before = $audit();

    $place = $this->getJson("/api/v1/appointment-locations/{$this->place->public_id}")->assertOk()->json('data');
    $this->patchJson("/api/v1/appointment-locations/{$this->place->public_id}", $place)->assertOk()->assertJsonPath('data.hours', $place['hours']);
    $closure = $this->getJson("/api/v1/appointment-closures/{$this->closure->public_id}")->assertOk()->json('data');
    $this->patchJson("/api/v1/appointment-closures/{$this->closure->public_id}", $closure)->assertOk()->assertJsonPath('data.location', $closure['location']);
    // An every-place closure too
    $every = $this->postJson('/api/v1/appointment-closures', ['date_from' => '2026-12-16', 'date_to' => '2026-12-17', 'yearly' => true])->assertCreated()->json('data');
    $before++;
    $this->patchJson("/api/v1/appointment-closures/{$every['id']}", $every)->assertOk()->assertJsonPath('data.location', null);

    // Nothing changed, so nothing new in the audit log
    expect($audit())->toBe($before);
});
