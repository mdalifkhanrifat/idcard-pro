<?php

use App\Enums\BookingAudience;
use App\Models\AppointmentClosure;
use App\Models\AppointmentLocation;
use App\Models\User;
use App\Services\Appointments\AppointmentLoad;
use App\Services\Appointments\AvailabilityService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
| What a place has free (5.3d): the organization's settings, the place's week, capacities and kinds, its
| closures and what is booked, in the organization's days and wall clock. 16 October 2026 is a Friday; the
| place's week is Sunday to Thursday, 9 to 5.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->settings = function (array $appointments = []): void {
        $this->tenant->forceFill(['settings' => [...(array) $this->tenant->settings, 'appointments' => ['enabled' => true, 'booking' => ['staff', 'public'], 'slot_minutes' => 30, ...$appointments]]])->save();
        $this->owner->unsetRelation('tenant');
    };
    $this->place = fn (array $attributes = []) => app(TenantContext::class)->run($this->tenant, fn () => AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, ...$attributes]));
    $this->closure = fn (array $attributes) => app(TenantContext::class)->run($this->tenant, fn () => AppointmentClosure::factory()->create(['tenant_id' => $this->tenant->id, ...$attributes]));
    $this->free = fn (AppointmentLocation $place, string $from, string $to, string $now, string $service = 'new_card', BookingAudience $audience = BookingAudience::Staff) => app(TenantContext::class)->run(
        $this->tenant->fresh(),
        fn () => app(AvailabilityService::class)->for($place, $service, $from, $to, $audience, CarbonImmutable::parse($now)),
    );
    $this->starts = fn (array $day) => array_map(fn (array $slot) => substr($slot['start'], 11, 5), $day['slots']);
    ($this->settings)();
});

it('lays out a day\'s time slots in the organization\'s wall clock, weekdays closed by the place\'s week', function () {
    $free = ($this->free)(($this->place)(), '2026-10-17', '2026-10-19', '2026-10-16 03:00:00');

    expect($free->scheduling)->toBe('slot')->and($free->unavailable)->toBeNull()
        ->and($free->service)->toBe(['key' => 'new_card', 'name' => 'New card', 'slots' => 1])
        ->and(array_column($free->days, 'date'))->toBe(['2026-10-17', '2026-10-18', '2026-10-19'])
        ->and(array_column($free->days, 'open'))->toBe([false, true, true])
        ->and($free->days[0]['slots'])->toBe([])
        ->and($free->days[1]['slots'])->toHaveCount(16)
        ->and($free->days[1]['slots'][0])->toBe(['start' => '2026-10-18T09:00:00+06:00', 'end' => '2026-10-18T09:30:00+06:00', 'capacity' => 1, 'booked' => 0, 'available' => 1])
        ->and($free->days[1]['slots'][15]['end'])->toBe('2026-10-18T17:00:00+06:00');
});

it('starts from the organization\'s today and stops at the days ahead, on either side of Dhaka\'s midnight', function () {
    ($this->settings)(['days_ahead' => 3]);
    $place = ($this->place)(['hours' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['09:00', '17:00']])]);

    // 23:30 on the 16th in Dhaka: today is the 16th, its slots over
    $before = ($this->free)($place, '2026-10-01', '2026-10-30', '2026-10-16 17:30:00');
    // 00:30 on the 17th in Dhaka, still the 16th in UTC: today is the 17th
    $after = ($this->free)($place, '2026-10-01', '2026-10-30', '2026-10-16 18:30:00');

    expect([$before->from, $before->to])->toBe(['2026-10-16', '2026-10-19'])
        ->and($before->days[0]['open'])->toBeTrue()->and($before->days[0]['slots'])->toBe([])
        ->and([$after->from, $after->to])->toBe(['2026-10-17', '2026-10-20'])
        ->and(($this->starts)($after->days[0])[0])->toBe('09:00')
        // Asked only for days already gone
        ->and(($this->free)($place, '2026-10-01', '2026-10-05', '2026-10-16 18:30:00')->days)->toBe([]);
});

it('keeps the public link the notice ahead, while staff book up to now, and offers it only the public kinds', function () {
    ($this->settings)(['notice_hours' => 2, 'services' => ['biometrics' => ['public' => false]]]);
    $place = ($this->place)();
    // Sunday 10:10 in Dhaka
    $now = '2026-10-18 04:10:00';

    expect(($this->starts)(($this->free)($place, '2026-10-18', '2026-10-18', $now)->days[0])[0])->toBe('10:30')
        ->and(($this->starts)(($this->free)($place, '2026-10-18', '2026-10-18', $now, 'new_card', BookingAudience::Public)->days[0])[0])->toBe('12:30')
        ->and(($this->free)($place, '2026-10-18', '2026-10-18', $now, 'biometrics', BookingAudience::Public)->unavailable)->toBe('service_not_public')
        ->and(($this->free)($place, '2026-10-18', '2026-10-18', $now, 'biometrics')->unavailable)->toBeNull();
});

it('fits a visit of several slots inside one opening period', function () {
    ($this->settings)(['slot_minutes' => 60, 'services' => ['renewal' => ['slots' => 2]]]);
    $place = ($this->place)(['hours' => ['mon' => [['14:00', '17:00'], ['09:00', '13:00']]]]);
    $day = ($this->free)($place, '2026-10-19', '2026-10-19', '2026-10-16 03:00:00', 'renewal')->days[0];

    expect(($this->starts)($day))->toBe(['09:00', '10:00', '11:00', '14:00', '15:00'])
        ->and($day['slots'][2]['end'])->toBe('2026-10-19T13:00:00+06:00')
        // A period running to midnight
        ->and(($this->starts)(($this->free)(($this->place)(['hours' => ['tue' => [['22:00', '24:00']]]]), '2026-10-20', '2026-10-20', '2026-10-16 03:00:00', 'renewal')->days[0]))->toBe(['22:00']);
});

it('closes the days of the place\'s closures and of every place\'s, yearly ones too, with their reason', function () {
    $place = ($this->place)();
    $other = ($this->place)();
    ($this->closure)(['date_from' => '2025-10-19', 'date_to' => '2025-10-19', 'yearly' => true, 'reason' => 'Every place, yearly', 'reason_local' => 'প্রতি বছর']);
    ($this->closure)(['location_id' => $place->id, 'date_from' => '2026-10-20', 'date_to' => '2026-10-20', 'reason' => 'This place']);
    ($this->closure)(['location_id' => $other->id, 'date_from' => '2026-10-21', 'date_to' => '2026-10-21', 'reason' => 'Another place']);

    $days = ($this->free)($place, '2026-10-18', '2026-10-21', '2026-10-16 03:00:00')->days;

    expect(array_column($days, 'open'))->toBe([true, false, false, true])
        ->and($days[1]['closure'])->toMatchArray(['reason' => 'Every place, yearly', 'reason_local' => 'প্রতি বছর'])
        ->and($days[1]['slots'])->toBe([])
        ->and($days[2]['closure']['reason'])->toBe('This place')
        ->and($days[3]['closure'])->toBeNull();
});

it('takes what is booked off the capacity: per slot, the fullest slot of a longer visit deciding', function () {
    ($this->settings)(['slot_minutes' => 60, 'services' => ['renewal' => ['slots' => 2]]]);
    $place = ($this->place)(['slot_capacity' => 2]);
    $at = fn (string $time) => CarbonImmutable::parse("2026-10-19 {$time}", 'Asia/Dhaka')->getTimestamp();
    app()->instance(AppointmentLoad::class, new class([$at('10:00') => 1, $at('11:00') => 2]) implements AppointmentLoad
    {
        public function __construct(private array $slots) {}

        public function slots(AppointmentLocation $location, CarbonImmutable $from, CarbonImmutable $to, int $slotMinutes, ?int $except = null): array
        {
            return $this->slots;
        }

        public function days(AppointmentLocation $location, string $from, string $to, ?int $except = null): array
        {
            return ['2026-10-19' => 45];
        }
    });

    $slots = collect(($this->free)($place, '2026-10-19', '2026-10-19', '2026-10-16 03:00:00', 'renewal')->days[0]['slots'])->keyBy(fn (array $slot) => substr($slot['start'], 11, 5));
    expect($slots['09:00'])->toMatchArray(['booked' => 1, 'available' => 1])
        ->and($slots['10:00'])->toMatchArray(['booked' => 2, 'available' => 0])
        ->and($slots['11:00'])->toMatchArray(['booked' => 2, 'available' => 0])
        ->and($slots['12:00'])->toMatchArray(['booked' => 0, 'available' => 2]);

    // Day scheduling: places of the day
    ($this->settings)(['scheduling' => 'day']);
    $day = ($this->free)($place, '2026-10-19', '2026-10-19', '2026-10-16 03:00:00')->days[0];
    expect($day)->toBe(['date' => '2026-10-19', 'open' => true, 'closure' => null, 'capacity' => 50, 'booked' => 45, 'available' => 5]);
});

it('books a day in turn while there is time to come before it closes', function () {
    ($this->settings)(['scheduling' => 'day', 'notice_hours' => 2]);
    $place = ($this->place)(['day_capacity' => 40]);
    // Sunday 15:30 in Dhaka: staff still can, the public link (two hours ahead) not today
    $now = '2026-10-18 09:30:00';

    expect(($this->free)($place, '2026-10-18', '2026-10-19', $now)->days[0])->toMatchArray(['open' => true, 'available' => 40])
        ->and(array_column(($this->free)($place, '2026-10-18', '2026-10-19', $now, 'new_card', BookingAudience::Public)->days, 'available'))->toBe([0, 40])
        ->and(($this->free)($place, '2026-10-17', '2026-10-17', $now)->days)->toBe([])
        ->and(($this->free)($place, '2026-10-24', '2026-10-24', $now)->days[0])->toMatchArray(['open' => false, 'available' => 0]);
});

it('follows a time zone with daylight saving: offsets change, a skipped hour has no slot', function () {
    $this->tenant->update(['timezone' => 'America/New_York']);
    ($this->settings)(['slot_minutes' => 60]);
    // 14 March 2027: clocks go from 02:00 to 03:00
    $place = ($this->place)(['hours' => ['sat' => [['09:00', '10:00']], 'sun' => [['01:00', '04:00']], 'mon' => [['09:00', '10:00']]]]);
    $days = ($this->free)($place, '2027-03-13', '2027-03-15', '2027-03-10 12:00:00')->days;

    expect(array_column($days[1]['slots'], 'start'))->toBe(['2027-03-14T01:00:00-05:00', '2027-03-14T03:00:00-04:00'])
        ->and($days[0]['slots'][0]['start'])->toBe('2027-03-13T09:00:00-05:00')
        ->and($days[2]['slots'][0]['start'])->toBe('2027-03-15T09:00:00-04:00');
});

it('says why nothing can be booked', function () {
    $place = ($this->place)(['services' => ['renewal']]);
    $why = fn (string $service = 'renewal', BookingAudience $audience = BookingAudience::Staff, ?AppointmentLocation $at = null) => ($this->free)($at ?? $place, '2026-10-18', '2026-10-19', '2026-10-16 03:00:00', $service, $audience);

    expect($why('new_card')->unavailable)->toBe('not_at_place')
        ->and($why('passport')->unavailable)->toBe('unknown_service')
        ->and($why('renewal', BookingAudience::Staff, ($this->place)(['is_active' => false]))->unavailable)->toBe('place_off')
        ->and($why()->days)->toHaveCount(2);

    ($this->settings)(['services' => ['renewal' => ['enabled' => false]]]);
    expect($why()->unavailable)->toBe('service_off')->and($why()->days)->toBe([]);
    ($this->settings)(['booking' => ['staff']]);
    expect($why('renewal', BookingAudience::Public)->unavailable)->toBe('not_public');
    ($this->settings)(['booking' => ['public']]);
    expect($why()->unavailable)->toBe('not_staff');
    ($this->settings)(['enabled' => false]);
    expect($why()->unavailable)->toBe('off');

    // A preview: as it will be once appointments and the place are on; the other rules hold
    $preview = fn (string $service = 'renewal', ?AppointmentLocation $at = null) => app(TenantContext::class)->run(
        $this->tenant->fresh(),
        fn () => app(AvailabilityService::class)->for($at ?? $place, $service, '2026-10-18', '2026-10-19', BookingAudience::Staff, CarbonImmutable::parse('2026-10-16 03:00:00'), preview: true),
    );
    expect($preview()->unavailable)->toBeNull()->and($preview()->days)->toHaveCount(2)
        ->and($preview('renewal', ($this->place)(['is_active' => false, 'services' => ['renewal']]))->unavailable)->toBeNull()
        ->and($preview('new_card')->unavailable)->toBe('not_at_place');
    ($this->settings)(['enabled' => false, 'services' => ['renewal' => ['enabled' => false]]]);
    expect($preview()->unavailable)->toBe('service_off');
});

it('answers on the API for those who see appointments or may change the settings, in the reader\'s language', function () {
    $place = ($this->place)();
    $url = "/api/v1/appointment-locations/{$place->public_id}/availability";
    foreach (['Administrator', 'Viewer', 'Operator'] as $role) {
        Sanctum::actingAs(tenantUser($this->tenant, $role));
        $this->getJson("{$url}?service=new_card")->assertOk();
    }
    Sanctum::actingAs(app(TenantContext::class)->run($this->tenant, fn () => User::factory()->create(['tenant_id' => $this->tenant->id])));
    $this->getJson("{$url}?service=new_card")->assertForbidden();

    Sanctum::actingAs($this->owner);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 03:00:00'));
    $week = $this->getJson("{$url}?service=new_card")->assertOk();
    expect($week->json('data.from'))->toBe('2026-10-16')->and($week->json('data.to'))->toBe('2026-10-22')
        ->and($week->json('data.days'))->toHaveCount(7)
        ->and($week->json('data.days.2.slots.0.start'))->toBe('2026-10-18T09:00:00+06:00');

    $public = $this->getJson("{$url}?service=new_card&from=2026-10-18&to=2026-10-18&audience=public", ['Accept-Language' => 'bn'])->assertOk();
    expect($public->json('data.service.name'))->toBe('নতুন কার্ড')->and($public->json('data.unavailable'))->toBeNull();

    ($this->settings)(['enabled' => false]);
    $this->getJson("{$url}?service=new_card", ['Accept-Language' => 'bn'])->assertOk()
        ->assertJsonPath('data.unavailable', ['key' => 'off', 'message' => 'প্রতিষ্ঠানে অ্যাপয়েন্টমেন্ট বন্ধ।'])->assertJsonPath('data.days', []);
    $this->getJson("{$url}?service=new_card&preview=1")->assertOk()->assertJsonPath('data.unavailable', null)->assertJsonCount(7, 'data.days');
    $this->getJson("{$url}?service=new_card&preview=maybe", ['Accept-Language' => 'bn'])->assertUnprocessable()->assertJsonValidationErrors('preview');

    $this->getJson("{$url}?from=2026-10-01&to=2026-12-31&audience=everyone", ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors.service', ['কাজের ধরন দিতে হবে।'])->assertJsonPath('errors.audience', ['বাছাই করা কার জন্য সঠিক নয়।']);
    $this->getJson("{$url}?service=new_card&from=2026-10-01&to=2026-12-31", ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors.to', ['একবারে সর্বোচ্চ ৬২ দিনের জন্য দেখা যায়।']);

    // Another organization's place is not there
    [, $otherOwner] = tenantWithOwner('globex', 'owner@globex.test');
    Sanctum::actingAs($otherOwner);
    $this->getJson("{$url}?service=new_card")->assertNotFound();
});
