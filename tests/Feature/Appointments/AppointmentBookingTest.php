<?php

use App\Enums\FamilyRelation;
use App\Models\Appointment;
use App\Models\AppointmentClosure;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;

/*
| Booking an appointment (5.4b): by the organization's settings and the same rules as the free times — checked
| under a lock on the place —, the person's open bookings, family members, approval; a short number; the
| free times count what is booked. 16 October 2026 is a Friday in Dhaka; the place's week is Sunday to
| Thursday, 9 to 5.
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->inTenant = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    $this->settings = function (array $appointments = []): void {
        $this->tenant->forceFill(['settings' => [...(array) $this->tenant->fresh()->settings, 'appointments' => ['enabled' => true, 'booking' => ['staff', 'public'], 'slot_minutes' => 30, ...$appointments]]])->save();
        foreach ([$this->owner, ...($this->others ?? [])] as $user) {
            $user->unsetRelation('tenant');
        }
    };
    ($this->settings)();
    $this->place = ($this->inTenant)(fn () => AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'slot_capacity' => 2, 'day_capacity' => 2]));
    $this->holder = ($this->inTenant)(fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id]));
    $this->book = fn (array $values = [], array $headers = []) => $this->postJson('/api/v1/appointments', [
        'location' => $this->place->public_id, 'service' => 'new_card', 'cardholder' => $this->holder->public_id, 'date' => '2026-10-18', 'time' => '09:30', ...$values,
    ], $headers);
    $this->travelTo(now()->parse('2026-10-16 03:00:00'));
});

it('books a free time: confirmed, numbered, on the organization\'s clock, in the audit log', function () {
    Sanctum::actingAs($this->owner);

    $response = ($this->book)(['note' => 'Bring the old card'])->assertCreated();
    expect($response->json('data'))->toMatchArray([
        'number' => 'A-26-00001', 'date' => '2026-10-18', 'starts_at' => '2026-10-18T09:30:00+06:00', 'ends_at' => '2026-10-18T10:00:00+06:00',
        'status' => ['key' => 'confirmed', 'name' => 'Confirmed'], 'source' => ['key' => 'staff', 'name' => 'Staff'], 'note' => 'Bring the old card', 'family_member' => null,
        'service' => ['key' => 'new_card', 'name' => 'New card'],
    ])
        ->and($response->json('data.location.id'))->toBe($this->place->public_id)
        ->and($response->json('data.cardholder.id'))->toBe($this->holder->public_id)
        ->and($response->json('data.booked_by.name'))->toBe($this->owner->name);
    $stored = ($this->inTenant)(fn () => Appointment::query()->sole());
    expect($stored->starts_at->toDateTimeString())->toBe('2026-10-18 03:30:00')
        ->and($this->getJson('/api/v1/audit/activities?filter[log_name]=appointments')->json('data.0.event'))->toBe('created');

    // In Bangla the names follow the reader
    expect(($this->book)(['time' => '10:00', 'service' => 'renewal'], ['Accept-Language' => 'bn'])->assertCreated()->json('data.status.name'))->toBe('নিশ্চিত');
});

it('counts what is booked in the free times, and refuses a full time, a full day and the open-bookings limit', function () {
    Sanctum::actingAs($this->owner);
    $second = ($this->inTenant)(fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id]));
    $third = ($this->inTenant)(fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id]));

    ($this->book)()->assertCreated();
    $slot = fn () => collect($this->getJson("/api/v1/appointment-locations/{$this->place->public_id}/availability?service=new_card&from=2026-10-18&to=2026-10-18")->json('data.days.0.slots'))->firstWhere('start', '2026-10-18T09:30:00+06:00');
    expect($slot())->toMatchArray(['capacity' => 2, 'booked' => 1, 'available' => 1]);

    // The same person again for the same kind: one open booking at most (the default)
    ($this->book)(['time' => '11:00'], ['Accept-Language' => 'bn'])->assertUnprocessable()->assertJsonPath('errors.service', ['এই কাজের ধরনে এই ব্যক্তির আগেই ১টা খোলা বুকিং আছে।']);
    ($this->book)(['cardholder' => $second->public_id])->assertCreated();
    expect($slot())->toMatchArray(['booked' => 2, 'available' => 0]);
    ($this->book)(['cardholder' => $third->public_id])->assertUnprocessable()->assertJsonValidationErrors('time');

    // A kind of two slots counts in both
    ($this->settings)(['services' => ['biometrics' => ['slots' => 2]]]);
    ($this->book)(['cardholder' => $third->public_id, 'service' => 'biometrics', 'time' => '13:00'])->assertCreated()->assertJsonPath('data.ends_at', '2026-10-18T14:00:00+06:00');
    $at = fn (string $start) => collect($this->getJson("/api/v1/appointment-locations/{$this->place->public_id}/availability?service=new_card&from=2026-10-18&to=2026-10-18")->json('data.days.0.slots'))->firstWhere('start', $start)['booked'];
    expect($at('2026-10-18T13:00:00+06:00'))->toBe(1)->and($at('2026-10-18T13:30:00+06:00'))->toBe(1)->and($at('2026-10-18T14:00:00+06:00'))->toBe(0);

    // A day booked in turn
    ($this->settings)(['scheduling' => 'day', 'open_per_service' => 5]);
    ($this->book)(['date' => '2026-10-19', 'time' => null])->assertCreated()->assertJsonPath('data.starts_at', null);
    ($this->book)(['date' => '2026-10-19', 'cardholder' => $second->public_id])->assertCreated();
    ($this->book)(['date' => '2026-10-19', 'cardholder' => $third->public_id], ['Accept-Language' => 'bn'])->assertUnprocessable()->assertJsonPath('errors.date', ['এই দিন ভরা; অন্য দিন বেছে নিন।']);
});

it('follows the free times\' rules: the time, closed days, days ahead, the place and the kind of visit', function () {
    Sanctum::actingAs($this->owner);
    ($this->inTenant)(fn () => AppointmentClosure::factory()->create(['tenant_id' => $this->tenant->id, 'date_from' => '2026-10-20', 'date_to' => '2026-10-20', 'reason' => 'Audit']));
    $errors = fn (array $values) => ($this->book)($values, ['Accept-Language' => 'bn'])->assertUnprocessable()->json('errors') ?? ($this->book)($values)->json();

    expect($errors(['time' => null]))->toBe(['time' => ['একটা সময় বেছে নিন।']])
        ->and(array_keys($errors(['time' => '09:10'])))->toBe(['time'])
        ->and(array_keys($errors(['time' => '17:00'])))->toBe(['time'])
        // Friday: the place does not work; Tuesday: closed for every place
        ->and($errors(['date' => '2026-10-16']))->toBe(['date' => ['এই দিনে জায়গাটা বন্ধ।']])
        ->and($errors(['date' => '2026-10-20']))->toBe(['date' => ['এই দিনে জায়গাটা বন্ধ: Audit।']])
        ->and(array_keys($errors(['date' => '2026-10-15'])))->toBe(['date'])
        ->and(array_keys($errors(['date' => '2026-12-31'])))->toBe(['date']);

    // Staff book up to now: today's past time is gone, a later one is free (Thursday 15 Oct is past; use Sunday's)
    $this->travelTo(now()->parse('2026-10-18 04:00:00'));
    expect(array_keys($errors(['time' => '09:30'])))->toBe(['time']);
    ($this->book)(['time' => '10:30'])->assertCreated();

    $this->place->update(['services' => ['renewal']]);
    expect($errors(['time' => '11:00', 'cardholder' => ($this->inTenant)(fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id]))->public_id]))->toBe(['location' => ['এই জায়গায় এই কাজের ধরন নেওয়া হয় না।']]);
    ($this->settings)(['services' => ['renewal' => ['enabled' => false]]]);
    expect(array_keys($errors(['service' => 'renewal', 'time' => '11:00'])))->toBe(['service']);
    $this->place->update(['is_active' => false]);
    expect(array_keys($errors(['service' => 'new_card'])))->toBe(['location']);
    ($this->settings)(['enabled' => false]);
    ($this->book)()->assertUnprocessable()->assertJsonPath('message', 'Appointments are turned off for the organization.');
});

it('books for a family member when the organization allows, theirs only, and waits for approval where bookings are approved', function () {
    $member = ($this->inTenant)(fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id, 'relation' => FamilyRelation::cases()[0]]));
    $other = ($this->inTenant)(fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => Cardholder::factory()->create(['tenant_id' => $this->tenant->id])->id]));
    Sanctum::actingAs($this->owner);

    // The member's own open booking does not count against the cardholder's
    ($this->book)()->assertCreated();
    ($this->book)(['family_member' => $member->public_id, 'time' => '10:00'])->assertCreated()->assertJsonPath('data.family_member.id', $member->public_id);
    ($this->book)(['family_member' => $other->public_id, 'time' => '11:00'], ['Accept-Language' => 'bn'])->assertUnprocessable()->assertJsonPath('errors.family_member', ['এই পরিবারের সদস্য এই কার্ডধারীর নন।']);
    ($this->settings)(['family_members' => false]);
    ($this->book)(['family_member' => $member->public_id, 'service' => 'renewal'])->assertUnprocessable()->assertJsonValidationErrors('family_member');

    // Approval: an Operator's booking waits, an Administrator (who may approve) confirms at once
    $this->others = [tenantUser($this->tenant, 'Operator'), tenantUser($this->tenant, 'Administrator')];
    ($this->settings)(['confirmation' => 'approval', 'open_per_service' => 5]);
    Sanctum::actingAs($this->others[0]);
    ($this->book)(['service' => 'renewal', 'time' => '12:00'])->assertCreated()->assertJsonPath('data.status.key', 'pending');
    Sanctum::actingAs($this->others[1]);
    ($this->book)(['service' => 'renewal', 'time' => '12:30'])->assertCreated()->assertJsonPath('data.status.key', 'confirmed');
});

it('lets only those who book appointments book, within the organization', function () {
    foreach (['Viewer' => 403, 'Operator' => 201, 'Administrator' => 201] as $role => $status) {
        Sanctum::actingAs(tenantUser($this->tenant, $role));
        $holder = ($this->inTenant)(fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id]));
        ($this->book)(['cardholder' => $holder->public_id])->assertStatus($status);
    }
    Sanctum::actingAs(($this->inTenant)(fn () => User::factory()->create(['tenant_id' => $this->tenant->id])));
    ($this->book)()->assertForbidden();

    // Another organization's place and cardholder are not there
    [$other, $otherOwner] = tenantWithOwner('globex', 'owner@globex.test');
    Sanctum::actingAs($otherOwner);
    ($this->book)()->assertUnprocessable()->assertJsonValidationErrors(['location', 'cardholder']);
    Sanctum::actingAs($this->owner);
    ($this->book)(['cardholder' => app(TenantContext::class)->run($other, fn () => Cardholder::factory()->create(['tenant_id' => $other->id]))->public_id])->assertUnprocessable()->assertJsonValidationErrors('cardholder');
});

it('keeps a booked cardholder, family member and place from deletion, with a message instead of a database error', function () {
    $member = ($this->inTenant)(fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id]));
    Sanctum::actingAs($this->owner);
    ($this->book)(['family_member' => $member->public_id])->assertCreated();

    $this->deleteJson("/api/v1/cardholders/{$this->holder->public_id}", [], ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('message', 'এই ব্যক্তির অ্যাপয়েন্টমেন্ট আছে, সেগুলো রেকর্ড হিসেবে থাকে (বাতিলগুলোও)। মোছার বদলে নিষ্ক্রিয় করুন।');
    $this->deleteJson("/api/v1/cardholders/{$this->holder->public_id}/family-members/{$member->public_id}")->assertUnprocessable()
        ->assertJsonPath('message', 'This family member has appointments, which are kept as records (cancelled ones too).');
    $this->deleteJson("/api/v1/appointment-locations/{$this->place->public_id}")->assertUnprocessable()
        ->assertJsonPath('message', 'This place has appointments, which are kept as records. Turn it off instead of deleting.');
    expect($this->holder->fresh())->not->toBeNull()->and($member->fresh())->not->toBeNull()->and($this->place->fresh())->not->toBeNull();

    // Without bookings they go as before
    $free = ($this->inTenant)(fn () => AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id]));
    $this->deleteJson("/api/v1/appointment-locations/{$free->public_id}")->assertNoContent();
});
