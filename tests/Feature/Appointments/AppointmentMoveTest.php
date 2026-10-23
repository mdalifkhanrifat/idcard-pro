<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/*
| Moving an appointment (5.4d): to another time, day or place by the rules of a new booking — its own place not
| counted against it —, number and status kept, only while ahead; its own answer taken back as it is; the kind
| of visit and the person never change. 16 October 2026 is a Friday in Dhaka; places are open Sunday to
| Thursday, 9 to 5, one person at a time; the booking is Sunday 18 October at 10:00.
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->inTenant = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    $this->operator = tenantUser($this->tenant, 'Operator');
    $this->settings = function (array $appointments = []): void {
        $this->tenant->forceFill(['settings' => ['appointments' => ['enabled' => true, 'slot_minutes' => 30, ...$appointments]]])->save();
        $this->owner->unsetRelation('tenant');
        $this->operator->unsetRelation('tenant');
    };
    ($this->settings)();
    $this->place = ($this->inTenant)(fn () => AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Head office', 'slot_capacity' => 1, 'day_capacity' => 1]));
    $this->branch = ($this->inTenant)(fn () => AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Branch', 'slot_capacity' => 1, 'day_capacity' => 1]));
    $this->holder = ($this->inTenant)(fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id]));
    $this->appointment = ($this->inTenant)(fn () => Appointment::factory()->at('2026-10-18', '2026-10-18 04:00:00', 30)->create([
        'tenant_id' => $this->tenant->id, 'number' => 'A-26-90001', 'location_id' => $this->place->id, 'cardholder_id' => $this->holder->id,
    ]));
    $this->move = fn (array $values, array $headers = [], ?Appointment $appointment = null) => $this->patchJson('/api/v1/appointments/'.($appointment ?? $this->appointment)->public_id, $values, $headers);
    $this->travelTo(now()->parse('2026-10-16 03:00:00'));
});

it('moves to another time, day or place, keeping its number and status, in the audit log', function () {
    Sanctum::actingAs($this->operator);

    expect(($this->move)(['time' => '11:30', 'note' => 'Moved by phone'])->assertOk()->json('data'))->toMatchArray([
        'number' => 'A-26-90001', 'status' => ['key' => 'confirmed', 'name' => 'Confirmed', 'open' => true], 'date' => '2026-10-18',
        'starts_at' => '2026-10-18T11:30:00+06:00', 'ends_at' => '2026-10-18T12:00:00+06:00', 'note' => 'Moved by phone',
    ]);
    // A day alone keeps the time of day; a place alone keeps both
    ($this->move)(['date' => '2026-10-19'])->assertOk()->assertJsonPath('data.starts_at', '2026-10-19T11:30:00+06:00');
    ($this->move)(['location' => $this->branch->public_id])->assertOk()->assertJsonPath('data.location.name', 'Branch')->assertJsonPath('data.starts_at', '2026-10-19T11:30:00+06:00');

    $logged = ($this->inTenant)(fn () => Activity::query()->where('log_name', 'appointments')->where('event', 'updated')->orderBy('id')->get());
    expect($logged)->toHaveCount(3)
        ->and($logged[0]->attribute_changes['old'])->toMatchArray(['note' => null])
        ->and(array_keys($logged[1]->attribute_changes['attributes']))->toEqualCanonicalizing(['date', 'starts_at', 'ends_at'])
        ->and(array_keys($logged[2]->attribute_changes['attributes']))->toBe(['location_id']);

    // The place it left is free again; the new one is taken
    $free = fn (AppointmentLocation $place) => collect($this->getJson("/api/v1/appointment-locations/{$place->public_id}/availability?service=new_card&from=2026-10-19&to=2026-10-19")->json('data.days.0.slots'))->firstWhere('start', '2026-10-19T11:30:00+06:00')['available'];
    expect($free($this->place))->toBe(1)->and($free($this->branch))->toBe(0);
});

it('does not count its own place against it, and refuses a taken time, a closed day and a past one', function () {
    Sanctum::actingAs($this->owner);
    // A visit of two slots at 10:00 holds 10:00 and 10:30; half an hour later it needs 10:30 (its own) and 11:00
    ($this->settings)(['services' => ['new_card' => ['slots' => 2]]]);
    ($this->inTenant)(fn () => $this->appointment->forceFill(['ends_at' => '2026-10-18 05:00:00'])->save());
    ($this->move)(['time' => '10:30'])->assertOk()->assertJsonPath('data.ends_at', '2026-10-18T11:30:00+06:00');

    $other = ($this->inTenant)(fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id]));
    $this->postJson('/api/v1/appointments', ['location' => $this->place->public_id, 'service' => 'renewal', 'cardholder' => $other->public_id, 'date' => '2026-10-18', 'time' => '13:00'])->assertCreated();
    ($this->move)(['time' => '12:30'], ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors.time', ['এই সময় খালি নেই (বুক হয়ে গেছে, পেরিয়ে গেছে, বা জায়গার সময় নয়); অন্য সময় বেছে নিন।']);
    ($this->move)(['date' => '2026-10-23'])->assertUnprocessable()->assertJsonPath('errors.date', ['The place is closed on this day.']);
    ($this->move)(['date' => '2026-10-15'])->assertUnprocessable()->assertJsonValidationErrors('date');
    // Nothing moved
    expect($this->appointment->fresh()->starts_at->toDateTimeString())->toBe('2026-10-18 04:30:00');
});

it('moves only an appointment still ahead, and a waiting one stays waiting', function () {
    ($this->settings)(['confirmation' => 'approval']);
    Sanctum::actingAs($this->operator);
    ($this->inTenant)(fn () => $this->appointment->forceFill(['status' => AppointmentStatus::Pending])->save());
    ($this->move)(['time' => '14:00'])->assertOk()->assertJsonPath('data.status.key', 'pending');

    Sanctum::actingAs($this->owner);
    ($this->move)(['time' => '14:30'])->assertOk()->assertJsonPath('data.status.key', 'pending');
    foreach ([AppointmentStatus::CheckedIn, AppointmentStatus::Cancelled, AppointmentStatus::Done] as $status) {
        ($this->inTenant)(fn () => $this->appointment->forceFill(['status' => $status])->save());
        ($this->move)(['time' => '15:00'], ['Accept-Language' => 'bn'])->assertUnprocessable()
            ->assertJsonPath('errors.status', ['শুধু সামনের (অপেক্ষায় বা নিশ্চিত) অ্যাপয়েন্টমেন্ট সরানো যায়; এটা এখন "'.__("appointments.statuses.{$status->value}", [], 'bn').'"।']);
    }
});

it('takes back its own answer unchanged, and refuses another kind of visit or person', function () {
    Sanctum::actingAs($this->owner);
    $answer = $this->getJson("/api/v1/appointments/{$this->appointment->public_id}")->json('data');
    $before = Activity::query()->count();

    expect(($this->move)($answer)->assertOk()->json('data'))->toBe($answer)
        ->and(Activity::query()->count())->toBe($before);

    $member = ($this->inTenant)(fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id]));
    ($this->move)(['service' => 'renewal', 'time' => '11:00'], ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors.service', ['অ্যাপয়েন্টমেন্টের কাজের ধরন বদলায় না; বাতিল করে নতুন বুকিং করুন।'])
        ->assertJsonMissingValidationErrors('time');
    ($this->move)([...$answer, 'service' => ['key' => 'renewal']])->assertUnprocessable()->assertJsonValidationErrors('service');
    ($this->move)(['cardholder' => ($this->inTenant)(fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id]))->public_id])->assertUnprocessable()->assertJsonValidationErrors('cardholder');
    ($this->move)(['family_member' => $member->public_id])->assertUnprocessable()->assertJsonValidationErrors('family_member');
    expect($this->appointment->fresh()->starts_at->toDateTimeString())->toBe('2026-10-18 04:00:00');
});

it('books a day anew by the day\'s room, and changes only a note while the module is off', function () {
    ($this->settings)(['scheduling' => 'day']);
    Sanctum::actingAs($this->owner);
    $day = ($this->inTenant)(fn () => Appointment::factory()->create(['tenant_id' => $this->tenant->id, 'location_id' => $this->place->id, 'cardholder_id' => $this->holder->id, 'service' => 'renewal', 'date' => '2026-10-19']));
    // The 18th is full (one a day, the timed booking there); its own day is not counted against it
    ($this->move)(['date' => '2026-10-18'], [], $day)->assertUnprocessable()->assertJsonPath('errors.date', ['This day is full; choose another.']);
    ($this->move)(['date' => '2026-10-20'], [], $day)->assertOk()->assertJsonPath('data.date', '2026-10-20')->assertJsonPath('data.starts_at', null);
    ($this->move)(['note' => 'Same day', 'date' => '2026-10-20'], [], $day)->assertOk();

    ($this->settings)(['enabled' => false]);
    ($this->move)(['note' => 'Left a message'], [], $day)->assertOk()->assertJsonPath('data.note', 'Left a message');
    ($this->move)(['date' => '2026-10-21'], [], $day)->assertUnprocessable()->assertJsonPath('message', 'Appointments are turned off for the organization.');
});

it('lets only those who book move an appointment, and only their organization\'s', function () {
    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    ($this->move)(['time' => '11:00'])->assertForbidden();

    [, $otherOwner] = tenantWithOwner('globex', 'owner@globex.test');
    Sanctum::actingAs($otherOwner);
    ($this->move)(['time' => '11:00'])->assertNotFound();
    expect($this->appointment->fresh()->starts_at->toDateTimeString())->toBe('2026-10-18 04:00:00');
});
