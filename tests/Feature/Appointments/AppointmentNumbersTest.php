<?php

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Services\Appointments\AppointmentNumbers;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| Appointments (5.4a): the table and a booking's short number in the organization's own format — counted per
| prefix (per year with {YY}) on the organization's counters, apart from the cards'.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->inTenant = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant->fresh(), $callback);
    $this->next = fn (string $day) => ($this->inTenant)(fn () => DB::transaction(fn () => app(AppointmentNumbers::class)->next(app(TenantContext::class)->get(), CarbonImmutable::parse($day))));
});

it('numbers bookings in the default format, per year, apart from another organization and from the cards', function () {
    expect(($this->next)('2026-10-16'))->toBe('A-26-00001')
        ->and(($this->next)('2026-12-31'))->toBe('A-26-00002')
        ->and(($this->next)('2027-01-01'))->toBe('A-27-00001')
        ->and(($this->inTenant)(fn () => app(AppointmentNumbers::class)->peek(app(TenantContext::class)->get(), CarbonImmutable::parse('2026-11-01'))))->toBe('A-26-00003');

    [$other] = tenantWithOwner('globex', 'owner@globex.test');
    expect(app(TenantContext::class)->run($other, fn () => DB::transaction(fn () => app(AppointmentNumbers::class)->next($other, CarbonImmutable::parse('2026-10-16')))))->toBe('A-26-00001');
    // Kept apart from the cards' counters on the same table
    expect(DB::table('card_sequences')->where('tenant_id', $this->tenant->id)->pluck('key')->sort()->values()->all())->toBe(['appointment:A-26-', 'appointment:A-27-']);
});

it('takes the organization\'s own format, checked in the reader\'s language', function () {
    Sanctum::actingAs($this->owner);
    $put = function (array $appointments, array $headers = []) {
        $response = $this->putJson('/api/v1/appointments/settings', ['appointments' => $appointments], $headers);
        $this->owner->unsetRelation('tenant');

        return $response;
    };

    $this->getJson('/api/v1/appointments/settings')->assertJsonPath('data.appointments.number_format', 'A-{YY}-{SEQ:5}');
    $put(['number_format' => 'VIS/{YYYY}{MM}/{SEQ:3}'])->assertOk()->assertJsonPath('data.appointments.number_format', 'VIS/{YYYY}{MM}/{SEQ:3}');
    expect(($this->next)('2026-03-05'))->toBe('VIS/202603/001');

    $put(['number_format' => '{CODE}-{SEQ:4}'], ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors', ['appointments.number_format' => ['শুধু {YYYY}, {YY}, {MM} আর {SEQ:n} ব্যবহার করুন।']]);
    $put(['number_format' => 'A-{YY}'], ['Accept-Language' => 'bn'])->assertUnprocessable()->assertJsonValidationErrors('appointments.number_format');
    $put(['number_format' => 'A {SEQ:4}'])->assertUnprocessable()->assertJsonValidationErrors('appointments.number_format');
    // Nothing failing was saved; a saved format gone wrong reads as the default
    expect($this->tenant->fresh()->appointmentSettings()['number_format'])->toBe('VIS/{YYYY}{MM}/{SEQ:3}');
    $this->tenant->forceFill(['settings' => [...$this->tenant->fresh()->settings, 'appointments' => ['number_format' => '{CODE}']]])->save();
    expect($this->tenant->fresh()->appointmentSettings()['number_format'])->toBe('A-{YY}-{SEQ:5}');
});

it('keeps a booking with its place, person, status and source, in the audit log', function () {
    $appointment = ($this->inTenant)(function () {
        $place = AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id]);
        $holder = Cardholder::factory()->create(['tenant_id' => $this->tenant->id]);

        return Appointment::factory()->at('2026-10-18', '2026-10-18 03:00:00', 30)->create(['tenant_id' => $this->tenant->id, 'location_id' => $place->id, 'cardholder_id' => $holder->id, 'number' => 'A-26-00001']);
    });

    $fresh = ($this->inTenant)(fn () => Appointment::query()->with(['location', 'cardholder'])->findOrFail($appointment->id));
    expect($fresh->status)->toBe(AppointmentStatus::Confirmed)->and($fresh->source)->toBe(AppointmentSource::Staff)
        ->and($fresh->date->toDateString())->toBe('2026-10-18')->and($fresh->ends_at->toDateTimeString())->toBe('2026-10-18 03:30:00')
        ->and(AppointmentStatus::holdingPlace())->toBe(['pending', 'confirmed', 'checked_in', 'done', 'no_show'])
        ->and(AppointmentStatus::open())->toBe(['pending', 'confirmed', 'checked_in'])
        ->and(DB::table('activity_log')->where('log_name', 'appointments')->where('subject_id', $appointment->id)->value('event'))->toBe('created');

    // Never deleted while booked: the place and the person stay
    expect(fn () => $fresh->location->delete())->toThrow(QueryException::class);
});
