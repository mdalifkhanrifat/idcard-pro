<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\FamilyMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;

/*
| A signed-in person's own appointments on the public link (5.6c): free times as the public sees them, booking for
| themself or a family member (source "public"), and cancelling and moving their own as far as the organization
| allows, until some hours before — by the services staff use, with the public's rules. The organization books
| through the public link only; 16 October 2026 is a Friday in Dhaka (09:00); the place is open Sunday to Thursday,
| 9 to 5, one person at a time.
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->settings = fn (array $more = []) => $this->tenant->forceFill(['timezone' => 'Asia/Dhaka', 'settings' => ['appointments' => [
        'enabled' => true, 'booking' => ['public'], 'slot_minutes' => 30, 'notice_hours' => 2, 'verification' => ['method' => 'date_of_birth'],
        'services' => ['renewal' => ['public' => false]], ...$more,
    ]]])->save();
    ($this->settings)();
    $in = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    $this->place = $in(fn () => AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Head office']));
    $this->jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => 'E-100', 'date_of_birth' => '1990-05-01', 'status' => 'active']);
    $this->son = $in(fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->jane->id, 'name' => 'Sam Doe', 'relation' => 'son']));
    $this->kim = cardholder($this->tenant, ['full_name' => 'Kim Lee', 'employee_no' => 'E-300', 'status' => 'active']);
    $this->kims = $in(fn () => Appointment::factory()->at('2026-10-19', '2026-10-19 05:00:00', 30)->create(['tenant_id' => $this->tenant->id, 'location_id' => $this->place->id, 'cardholder_id' => $this->kim->id, 'number' => 'A-26-90001']));
    $this->travelTo(now()->parse('2026-10-16 03:00:00'));

    $this->url = '/api/v1/public/acme/booking';
    // Signed in again after time travel (a session lasts 30 minutes unused)
    $this->signIn = function (): void {
        $challenge = $this->postJson("{$this->url}/identify", ['employee_no' => 'E-100'])->json('data.challenge');
        $this->headers = ['X-Booking-Session' => $this->postJson("{$this->url}/verify", ['challenge' => $challenge, 'date_of_birth' => '1990-05-01'])->json('data.token')];
    };
    ($this->signIn)();
    $this->book = fn (array $values = []) => $this->postJson("{$this->url}/appointments", ['location' => $this->place->public_id, 'service' => 'new_card', 'date' => '2026-10-18', 'time' => '10:00', ...$values], $this->headers);
});

it('shows free times as the public sees them, only to a signed-in person', function () {
    $free = fn (string $service) => $this->getJson("{$this->url}/availability?location={$this->place->public_id}&service={$service}&from=2026-10-18&to=2026-10-18", $this->headers);

    expect(collect($free('new_card')->assertOk()->json('data.days.0.slots'))->firstWhere('start', '2026-10-18T10:00:00+06:00')['available'])->toBe(1);
    // A kind not offered on the link
    $free('renewal')->assertOk()->assertJsonPath('data.unavailable.key', 'service_not_public');
    $this->getJson("{$this->url}/availability?location={$this->place->public_id}&service=new_card")->assertUnauthorized();
});

it('books for the person or their family member, as the public link, and lists only their own', function () {
    $mine = ($this->book)(['note' => 'Bring the old card'])->assertCreated()->json('data');
    expect($mine)->toMatchArray(['status' => ['key' => 'confirmed', 'name' => 'Confirmed', 'open' => true], 'date' => '2026-10-18', 'starts_at' => '2026-10-18T10:00:00+06:00', 'note' => 'Bring the old card', 'family_member' => null])
        ->and($mine['actions'])->toBe(['cancel' => ['allowed' => true, 'reason' => null], 'move' => ['allowed' => true, 'reason' => null]])
        ->and($mine)->not->toHaveKeys(['booked_by', 'source', 'cardholder']);
    $sons = ($this->book)(['family_member' => $this->son->public_id, 'time' => '11:00'])->assertCreated()->json('data');
    expect($sons['family_member'])->toBe(['id' => $this->son->public_id, 'name' => 'Sam Doe', 'relation' => 'son']);

    // Staff see it booked through the public link, by nobody of theirs
    $booked = Appointment::query()->withoutGlobalScopes()->where('public_id', $mine['id'])->first();
    expect($booked->source->value)->toBe('public')->and($booked->booked_by_id)->toBeNull();

    expect(array_column($this->getJson("{$this->url}/appointments", $this->headers)->assertOk()->json('data'), 'id'))->toBe([$mine['id'], $sons['id']]);
    // Another person's family member is not theirs to book for
    $kimsDaughter = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->kim->id]));
    ($this->book)(['family_member' => $kimsDaughter->public_id, 'time' => '12:00'])->assertUnprocessable()->assertJsonValidationErrors('family_member');
});

it('keeps to the public\'s rules: the hours\' notice and the kinds offered, and waits for approval where bookings are approved', function () {
    $this->travelTo(now()->parse('2026-10-18 03:30:00'));
    ($this->signIn)();
    ($this->book)(['time' => '10:30'])->assertUnprocessable()->assertJsonValidationErrors('time');
    ($this->book)(['service' => 'renewal', 'time' => '14:00'])->assertUnprocessable()->assertJsonValidationErrors('service');

    ($this->settings)(['confirmation' => 'approval']);
    ($this->book)(['time' => '14:00'])->assertCreated()->assertJsonPath('data.status.key', 'pending');
});

it('cancels and moves the person\'s own as far as the organization allows, and only their own', function () {
    $mine = ($this->book)()->json('data.id');

    $this->patchJson("{$this->url}/appointments/{$mine}", ['date' => '2026-10-18', 'time' => '11:00'], $this->headers)->assertOk()
        ->assertJsonPath('data.starts_at', '2026-10-18T11:00:00+06:00');
    $this->postJson("{$this->url}/appointments/{$this->kims->public_id}/cancel", [], $this->headers)->assertNotFound();
    $this->patchJson("{$this->url}/appointments/{$this->kims->public_id}", ['date' => '2026-10-20', 'time' => '10:00'], $this->headers)->assertNotFound();

    // Not offered on the link: said in its actions, and refused alike
    ($this->settings)(['changes' => ['cancel' => false, 'reschedule' => false, 'until_hours' => 2]]);
    $listed = $this->getJson("{$this->url}/appointments", $this->headers)->json('data.0.actions');
    expect($listed['cancel'])->toBe(['allowed' => false, 'reason' => 'Cancelling is not offered here. Please contact the office.']);
    $this->postJson("{$this->url}/appointments/{$mine}/cancel", [], $this->headers)->assertUnprocessable()->assertJsonPath('errors.status.0', $listed['cancel']['reason']);
    $this->patchJson("{$this->url}/appointments/{$mine}", ['date' => '2026-10-18', 'time' => '12:00'], $this->headers)->assertJsonPath('errors.status.0', $listed['move']['reason']);

    // Too close to its time (11:00 Dhaka, until 2 hours before)
    ($this->settings)();
    $this->travelTo(now()->parse('2026-10-18 03:30:00'));
    ($this->signIn)();
    $this->postJson("{$this->url}/appointments/{$mine}/cancel", [], $this->headers + ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'এখান থেকে বদল করা যায় শুধু ২ ঘণ্টা আগে পর্যন্ত। দয়া করে অফিসে যোগাযোগ করুন।');
    $this->travelTo(now()->parse('2026-10-18 02:30:00'));
    ($this->signIn)();
    $cancelled = $this->postJson("{$this->url}/appointments/{$mine}/cancel", ['reason' => 'Travelling'], $this->headers)->assertOk()->json('data');
    expect($cancelled)->toMatchArray(['status' => ['key' => 'cancelled', 'name' => 'Cancelled', 'open' => false], 'cancel_reason' => 'Travelling'])
        ->and(Appointment::query()->withoutGlobalScopes()->where('public_id', $mine)->value('status_changed_by_id'))->toBeNull();
});

it('lets staff of an organization that also books through them see the public booking as theirs to manage', function () {
    ($this->settings)(['booking' => ['staff', 'public']]);
    $mine = ($this->book)()->json('data.id');

    Sanctum::actingAs($this->owner);
    $this->getJson("/api/v1/appointments/{$mine}")->assertOk()->assertJsonPath('data.source.key', 'public')->assertJsonPath('data.booked_by', null)
        ->assertJsonPath('data.status.key', AppointmentStatus::Confirmed->value);
});
