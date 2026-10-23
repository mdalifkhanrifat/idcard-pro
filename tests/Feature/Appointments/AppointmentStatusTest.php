<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| Where an appointment stands (5.4d): cancelled by those who book while still ahead; taken through its steps by
| those who manage — approved, came in on its day, did not come once its time began, done — and back from each;
| restored from cancelled only while its time is still free. 16 October 2026 is a Friday in Dhaka; the place is
| open Sunday to Thursday, 9 to 5, one person at a time.
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->inTenant = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    $this->settings = function (array $appointments = []): void {
        $this->tenant->forceFill(['settings' => ['appointments' => ['enabled' => true, 'slot_minutes' => 30, ...$appointments]]])->save();
        foreach ([$this->owner, $this->operator, $this->administrator] as $user) {
            $user->unsetRelation('tenant');
        }
    };
    $this->operator = tenantUser($this->tenant, 'Operator');
    $this->administrator = tenantUser($this->tenant, 'Administrator');
    ($this->settings)();
    $this->place = ($this->inTenant)(fn () => AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'slot_capacity' => 1, 'day_capacity' => 1]));
    $this->holder = ($this->inTenant)(fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id]));
    // Sunday 18 October, 10:00 in Dhaka
    $this->booked = fn (array $values = []) => ($this->inTenant)(fn () => Appointment::factory()->at('2026-10-18', '2026-10-18 04:00:00', 30)->create([
        'tenant_id' => $this->tenant->id, 'location_id' => $this->place->id, 'cardholder_id' => $this->holder->id, ...$values,
    ]));
    $this->step = fn (Appointment $appointment, string $status, array $more = [], array $headers = []) => $this->postJson("/api/v1/appointments/{$appointment->public_id}/status", ['status' => $status, ...$more], $headers);
    $this->travelTo(now()->parse('2026-10-16 03:00:00'));
});

it('lets those who book cancel an appointment still ahead, with a reason, giving up its place', function () {
    $appointment = ($this->booked)();
    $free = fn () => collect($this->getJson("/api/v1/appointment-locations/{$this->place->public_id}/availability?service=new_card&from=2026-10-18&to=2026-10-18")->json('data.days.0.slots'))->firstWhere('start', '2026-10-18T10:00:00+06:00')['available'];

    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    $this->postJson("/api/v1/appointments/{$appointment->public_id}/cancel")->assertForbidden();

    Sanctum::actingAs($this->operator);
    expect($free())->toBe(0);
    $data = $this->postJson("/api/v1/appointments/{$appointment->public_id}/cancel", ['reason' => 'Travelling'])->assertOk()->json('data');
    expect($data['status'])->toBe(['key' => 'cancelled', 'name' => 'Cancelled'])
        ->and($data['cancel_reason'])->toBe('Travelling')
        ->and($data['status_changed_by']['name'])->toBe($this->operator->name)
        ->and($free())->toBe(1);
    $audit = ($this->inTenant)(fn () => $appointment->activitiesAsSubject()->latest('id')->first());
    expect($audit->attribute_changes['attributes'])->toMatchArray(['status' => 'cancelled', 'cancel_reason' => 'Travelling'])
        ->and($audit->attribute_changes['old']['status'])->toBe('confirmed');

    // Only once, and only while ahead
    $this->postJson("/api/v1/appointments/{$appointment->public_id}/cancel", [], ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors.status', ['শুধু সামনের (অপেক্ষায় বা নিশ্চিত) অ্যাপয়েন্টমেন্ট বাতিল করা যায়; এটা এখন "বাতিল"।']);
    $came = ($this->booked)(['status' => AppointmentStatus::CheckedIn, 'service' => 'renewal']);
    $this->postJson("/api/v1/appointments/{$came->public_id}/cancel")->assertUnprocessable()->assertJsonValidationErrors('status');

    // A waiting one too; another organization's is out of reach
    $waiting = ($this->booked)(['status' => AppointmentStatus::Pending, 'service' => 'biometrics', 'date' => '2026-10-19', 'starts_at' => '2026-10-19 04:00:00', 'ends_at' => '2026-10-19 04:30:00']);
    $this->postJson("/api/v1/appointments/{$waiting->public_id}/cancel")->assertOk()->assertJsonPath('data.cancel_reason', null);
    [, $otherOwner] = tenantWithOwner('globex', 'owner@globex.test');
    Sanctum::actingAs($otherOwner);
    $this->postJson("/api/v1/appointments/{$came->public_id}/cancel")->assertNotFound();
    $this->postJson("/api/v1/appointments/{$came->public_id}/status", ['status' => 'done'])->assertNotFound();
});

it('takes an appointment through its steps — came in only on its day — and back from a mistake', function () {
    ($this->settings)(['confirmation' => 'approval']);
    $appointment = ($this->booked)(['status' => AppointmentStatus::Pending]);

    // Those who only book cannot take steps
    Sanctum::actingAs($this->operator);
    ($this->step)($appointment, 'confirmed')->assertForbidden();

    Sanctum::actingAs($this->administrator);
    // Not every step: a waiting one is not done
    ($this->step)($appointment, 'done', [], ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors.status', ['"অনুমোদনের অপেক্ষায়" অবস্থার অ্যাপয়েন্টমেন্ট "সম্পন্ন" করা যায় না।']);
    ($this->step)($appointment, 'confirmed')->assertOk()->assertJsonPath('data.status.key', 'confirmed')->assertJsonPath('data.status_changed_by.name', $this->administrator->name);
    // Came in: only on its day, by the organization's clock
    ($this->step)($appointment, 'checked_in', [], ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors.status', ['উপস্থিত শুধু অ্যাপয়েন্টমেন্টের দিনে (১৮/১০/২০২৬) দেওয়া যায়।']);
    $this->travelTo(now()->parse('2026-10-17 18:05:00')); // 00:05 on the 18th in Dhaka
    ($this->step)($appointment, 'checked_in')->assertOk();
    ($this->step)($appointment, 'done')->assertOk()->assertJsonPath('data.status.name', 'Done');
    // Back from a mistake, step by step
    ($this->step)($appointment, 'checked_in')->assertOk();
    ($this->step)($appointment, 'confirmed')->assertOk();
    ($this->step)($appointment, 'cancelled', ['reason' => 'Turned down'])->assertOk()->assertJsonPath('data.cancel_reason', 'Turned down');

    $steps = ($this->inTenant)(fn () => $appointment->activitiesAsSubject()->orderBy('id')->get()->map(fn ($a) => ($a->attribute_changes['old']['status'] ?? '').'>'.($a->attribute_changes['attributes']['status'] ?? ''))->all());
    expect($steps)->toBe(['>pending', 'pending>confirmed', 'confirmed>checked_in', 'checked_in>done', 'done>checked_in', 'checked_in>confirmed', 'confirmed>cancelled']);
});

it('records when a step was taken as the moment it was, not the organization\'s wall clock', function () {
    // 03:00 UTC is 09:00 in Dhaka: a step and a cancel both store and answer the moment itself
    $appointment = ($this->booked)();
    Sanctum::actingAs($this->administrator);
    $stepped = ($this->step)($appointment, 'cancelled')->assertOk()->json('data.status_changed_at');
    expect(CarbonImmutable::parse($stepped)->equalTo(now()))->toBeTrue()
        ->and(DB::table('appointments')->where('id', $appointment->id)->value('status_changed_at'))->toBe('2026-10-16 03:00:00');

    Sanctum::actingAs($this->operator);
    $other = ($this->booked)(['cardholder_id' => ($this->inTenant)(fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id]))->id, 'starts_at' => '2026-10-18 05:00:00', 'ends_at' => '2026-10-18 05:30:00']);
    $this->travelTo(now()->parse('2026-10-16 19:30:00'));
    $this->postJson("/api/v1/appointments/{$other->public_id}/cancel")->assertOk();
    expect(DB::table('appointments')->where('id', $other->id)->value('status_changed_at'))->toBe('2026-10-16 19:30:00');
});

it('marks "did not come" only once the time has begun, or a day booked once it is over', function () {
    Sanctum::actingAs($this->owner);
    $appointment = ($this->booked)();

    ($this->step)($appointment, 'no_show', [], ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors.status', ['"আসেননি" দেওয়া যায় অ্যাপয়েন্টমেন্টের সময় শুরু হওয়ার পর।']);
    $this->travelTo(now()->parse('2026-10-18 03:59:00'));
    ($this->step)($appointment, 'no_show')->assertUnprocessable();
    $this->travelTo(now()->parse('2026-10-18 04:00:00'));
    ($this->step)($appointment, 'no_show')->assertOk()->assertJsonPath('data.status.key', 'no_show');
    // Back: confirmed again (it never gave up its place)
    ($this->step)($appointment, 'confirmed')->assertOk();

    // A whole day: once the day is over in Dhaka
    $day = ($this->booked)(['service' => 'renewal', 'date' => '2026-10-18', 'starts_at' => null, 'ends_at' => null]);
    $this->travelTo(now()->parse('2026-10-18 17:59:00')); // 23:59 on the 18th
    ($this->step)($day, 'no_show')->assertUnprocessable()->assertJsonPath('errors.status', ['Mark "did not come" once the appointment\'s day is over.']);
    $this->travelTo(now()->parse('2026-10-18 18:00:00')); // the 19th
    ($this->step)($day, 'no_show')->assertOk();
});

it('restores a cancelled appointment only while its time is free and within the person\'s open bookings', function () {
    Sanctum::actingAs($this->owner);
    $appointment = ($this->booked)();
    $this->postJson("/api/v1/appointments/{$appointment->public_id}/cancel", ['reason' => 'By mistake'])->assertOk();

    // Restored: its place back, the reason gone, the same time
    ($this->step)($appointment, 'confirmed')->assertOk()->assertJsonPath('data.cancel_reason', null)
        ->assertJsonPath('data.starts_at', '2026-10-18T10:00:00+06:00')->assertJsonPath('data.number', $appointment->number);

    // Someone else took its time meanwhile
    $this->postJson("/api/v1/appointments/{$appointment->public_id}/cancel")->assertOk();
    $other = ($this->inTenant)(fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id]));
    $this->postJson('/api/v1/appointments', ['location' => $this->place->public_id, 'service' => 'new_card', 'cardholder' => $other->public_id, 'date' => '2026-10-18', 'time' => '10:00'])->assertCreated();
    ($this->step)($appointment, 'pending', [], ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors.status', ['ফেরানো যাচ্ছে না: এই সময় খালি নেই (বুক হয়ে গেছে, পেরিয়ে গেছে, বা জায়গার সময় নয়); অন্য সময় বেছে নিন। বদলে নতুন বুকিং করুন।']);

    // The person has booked the kind again meanwhile
    $this->postJson('/api/v1/appointments', ['location' => $this->place->public_id, 'service' => 'new_card', 'cardholder' => $this->holder->public_id, 'date' => '2026-10-18', 'time' => '11:00'])->assertCreated();
    ($this->step)($appointment, 'confirmed')->assertUnprocessable()->assertJsonPath('errors.status', ['This person already has 1 open booking(s) for this kind of visit.']);
    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled);
});

it('closes bookings left while the module is off, but does not restore one', function () {
    Sanctum::actingAs($this->owner);
    $ahead = ($this->booked)();
    $cancelled = ($this->booked)(['service' => 'renewal', 'status' => AppointmentStatus::Cancelled]);
    ($this->settings)(['enabled' => false]);

    $this->postJson("/api/v1/appointments/{$ahead->public_id}/cancel")->assertOk();
    ($this->step)($ahead, 'confirmed')->assertUnprocessable()->assertJsonPath('message', 'Appointments are turned off for the organization.');
    expect($cancelled->fresh()->status)->toBe(AppointmentStatus::Cancelled);
    ($this->step)(($this->booked)(['service' => 'biometrics', 'status' => AppointmentStatus::Pending]), 'confirmed')->assertOk();
});
