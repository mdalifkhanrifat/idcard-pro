<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Lang;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

/*
| What a user may do with an appointment now (5.5c): an answer about one appointment says it (`actions`) — move
| and cancel for those who book, the steps for those who manage, each allowed or why not by the change's own
| checks; what the role never allows is left out. And the free times as for moving an appointment (`except`).
| 16 October 2026 is a Friday in Dhaka; the place is open Sunday to Thursday, 9 to 5, one person at a time.
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->inTenant = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    $this->operator = tenantUser($this->tenant, 'Operator');
    $this->settings = function (array $appointments = []): void {
        $this->tenant->forceFill(['settings' => ['appointments' => ['enabled' => true, 'slot_minutes' => 30, ...$appointments]]])->save();
        foreach ([$this->owner, $this->operator] as $user) {
            $user->unsetRelation('tenant');
        }
    };
    ($this->settings)();
    $this->place = ($this->inTenant)(fn () => AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'slot_capacity' => 1, 'day_capacity' => 1]));
    $this->holder = ($this->inTenant)(fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id]));
    // Sunday 18 October, 10:00 in Dhaka
    $this->booked = fn (array $values = []) => ($this->inTenant)(fn () => Appointment::factory()->at('2026-10-18', '2026-10-18 04:00:00', 30)->create([
        'tenant_id' => $this->tenant->id, 'location_id' => $this->place->id, 'cardholder_id' => $this->holder->id, ...$values,
    ]));
    $this->actions = fn (Appointment $appointment, array $headers = []) => $this->getJson("/api/v1/appointments/{$appointment->public_id}", $headers)->assertOk()->json('data.actions');
    $this->steps = fn (array $actions) => collect($actions['steps'])->mapWithKeys(fn (array $step) => [$step['status']['key'] => $step])->all();
    // Every step offered as not allowed is refused by the change itself, with the very reason
    $this->agrees = function (Appointment $appointment, array $actions): void {
        foreach ($actions['steps'] as $step) {
            if (! $step['allowed']) {
                $refused = $this->postJson("/api/v1/appointments/{$appointment->public_id}/status", ['status' => $step['status']['key']])->assertUnprocessable();
                expect($refused->json('errors.status.0') ?? $refused->json('message'))->toBe($step['reason']);
            }
        }
    };
    $this->travelTo(now()->parse('2026-10-16 03:00:00'));
});

it('offers those who book to move and cancel, the steps only to those who manage, and nothing to those who see', function () {
    $appointment = ($this->booked)();

    Sanctum::actingAs($this->operator);
    expect(($this->actions)($appointment))->toBe(['move' => ['allowed' => true, 'reason' => null], 'cancel' => ['allowed' => true, 'reason' => null], 'steps' => []]);

    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    expect(($this->actions)($appointment))->toBe(['move' => null, 'cancel' => null, 'steps' => []]);

    // Those who also manage: the steps — cancelling once, as `cancel`
    Sanctum::actingAs($this->owner);
    $actions = ($this->actions)($appointment);
    expect($actions['move']['allowed'])->toBeTrue()->and($actions['cancel']['allowed'])->toBeTrue()
        ->and(array_keys(($this->steps)($actions)))->toBe(['checked_in', 'no_show']);

    // Those who manage but do not book: no moving; cancelling — turning down — as a step
    $manager = tenantUser($this->tenant, 'Operator');
    ($this->inTenant)(function () use ($manager) {
        $role = Role::create(['name' => 'Desk manager', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id]);
        $role->givePermissionTo(['appointments.view', 'appointments.manage']);
        $manager->syncRoles([$role]);
    });
    Sanctum::actingAs($manager->fresh());
    $waiting = ($this->booked)(['status' => AppointmentStatus::Pending, 'service' => 'renewal']);
    $actions = ($this->actions)($waiting);
    expect($actions['move'])->toBeNull()->and($actions['cancel'])->toBeNull()
        ->and($actions['steps'])->toBe([
            ['status' => ['key' => 'confirmed', 'name' => 'Confirmed'], 'label' => 'Approve', 'correction' => false, 'allowed' => true, 'reason' => null],
            ['status' => ['key' => 'cancelled', 'name' => 'Cancelled'], 'label' => 'Turn down', 'correction' => false, 'allowed' => true, 'reason' => null],
        ]);
    $this->postJson("/api/v1/appointments/{$waiting->public_id}/cancel")->assertForbidden();
    $this->postJson("/api/v1/appointments/{$waiting->public_id}/status", ['status' => 'cancelled', 'reason' => 'No papers'])->assertOk();

    // Not in the list (heavy there)
    Sanctum::actingAs($this->owner);
    expect($this->getJson('/api/v1/appointments?filter[past]=1')->assertOk()->json('data.0'))->not->toHaveKey('actions');
});

it('says why a step cannot be taken yet, as the change itself would, by the organization\'s clock', function () {
    Sanctum::actingAs($this->owner);
    $appointment = ($this->booked)();

    $actions = ($this->actions)($appointment, ['Accept-Language' => 'bn']);
    expect(($this->steps)($actions)['checked_in'])->toMatchArray(['label' => 'উপস্থিত', 'allowed' => false, 'reason' => 'উপস্থিত শুধু অ্যাপয়েন্টমেন্টের দিনে (১৮/১০/২০২৬) দেওয়া যায়।'])
        ->and(($this->steps)($actions)['no_show'])->toMatchArray(['label' => 'আসেননি', 'allowed' => false, 'reason' => '"আসেননি" দেওয়া যায় অ্যাপয়েন্টমেন্টের সময় শুরু হওয়ার পর।']);
    ($this->agrees)($appointment, ($this->actions)($appointment));

    // 00:05 on the 18th in Dhaka: came in, yes; did not come, not before 10:00
    $this->travelTo(now()->parse('2026-10-17 18:05:00'));
    $steps = ($this->steps)(($this->actions)($appointment));
    expect($steps['checked_in']['allowed'])->toBeTrue()->and($steps['no_show']['allowed'])->toBeFalse();
    $this->travelTo(now()->parse('2026-10-18 04:00:00'));
    expect(($this->steps)(($this->actions)($appointment))['no_show']['allowed'])->toBeTrue();

    // A step's answer says what comes next: came in → done, or back (a correction)
    $data = $this->postJson("/api/v1/appointments/{$appointment->public_id}/status", ['status' => 'checked_in'])->assertOk()->json('data');
    expect($data['actions']['move'])->toBe(['allowed' => false, 'reason' => 'Only an appointment still ahead (waiting or confirmed) can be moved; this one is "Came in".'])
        ->and($data['actions']['cancel']['allowed'])->toBeFalse()
        ->and(collect($data['actions']['steps'])->map(fn ($step) => [$step['label'], $step['correction'], $step['allowed']])->all())
        ->toBe([['Done', false, true], ['Back to confirmed (not come in)', true, true]]);
});

it('offers restoring a cancelled appointment only while its time is free, and nothing else', function () {
    Sanctum::actingAs($this->owner);
    $appointment = ($this->booked)();
    $data = $this->postJson("/api/v1/appointments/{$appointment->public_id}/cancel")->assertOk()->json('data');
    expect($data['actions']['move']['allowed'])->toBeFalse()
        ->and($data['actions']['cancel']['reason'])->toBe('Only an appointment still ahead (waiting or confirmed) can be cancelled; this one is "Cancelled".')
        ->and(collect($data['actions']['steps'])->map(fn ($step) => [$step['status']['key'], $step['label'], $step['correction'], $step['allowed']])->all())
        ->toBe([['confirmed', 'Restore as confirmed', true, true], ['pending', 'Restore as waiting for approval', true, true]]);

    // Someone else took its time meanwhile: both refused, with why
    $other = ($this->inTenant)(fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id]));
    $this->postJson('/api/v1/appointments', ['location' => $this->place->public_id, 'service' => 'new_card', 'cardholder' => $other->public_id, 'date' => '2026-10-18', 'time' => '10:00'])->assertCreated();
    $actions = ($this->actions)($appointment);
    expect(collect($actions['steps'])->pluck('allowed')->all())->toBe([false, false])
        ->and($actions['steps'][0]['reason'])->toStartWith('It cannot be restored: ');
    ($this->agrees)($appointment, $actions);
});

it('follows the organization\'s settings: no moving or restoring while appointments are off or staff do not book', function () {
    Sanctum::actingAs($this->owner);
    $appointment = ($this->booked)();
    $cancelled = ($this->booked)(['service' => 'renewal', 'status' => AppointmentStatus::Cancelled, 'date' => '2026-10-19', 'starts_at' => '2026-10-19 04:00:00', 'ends_at' => '2026-10-19 04:30:00']);

    ($this->settings)(['enabled' => false]);
    $actions = ($this->actions)($appointment);
    // Bookings left can still be cancelled and taken through their steps
    expect($actions['move'])->toBe(['allowed' => false, 'reason' => 'Appointments are turned off for the organization.'])
        ->and($actions['cancel']['allowed'])->toBeTrue()
        ->and(array_keys(($this->steps)($actions)))->toBe(['checked_in', 'no_show']);
    $restore = ($this->actions)($cancelled);
    expect(collect($restore['steps'])->pluck('reason')->unique()->all())->toBe(['Appointments are turned off for the organization.']);
    ($this->agrees)($cancelled, $restore);

    ($this->settings)(['booking' => ['public']]);
    expect(($this->actions)($appointment)['move'])->toBe(['allowed' => false, 'reason' => 'The organization\'s staff do not book appointments.']);
});

it('names every step in both languages', function () {
    foreach (AppointmentStatus::cases() as $from) {
        foreach ($from->next() as $to) {
            foreach (['en', 'bn'] as $locale) {
                expect(Lang::hasForLocale("appointments.steps.{$from->value}.{$to->value}", $locale))->toBeTrue("{$from->value} → {$to->value} ({$locale})");
            }
        }
    }
});

it('shows the free times as for moving an appointment — its own place free — to those who book', function () {
    $appointment = ($this->booked)();
    $url = "/api/v1/appointment-locations/{$this->place->public_id}/availability?service=new_card&from=2026-10-18&to=2026-10-18";
    $free = fn (string $query = '') => collect($this->getJson($url.$query)->assertOk()->json('data.days.0.slots'))->firstWhere('start', '2026-10-18T10:00:00+06:00')['available'];

    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    $this->getJson($url)->assertOk();
    $this->getJson("{$url}&except={$appointment->public_id}")->assertForbidden();

    Sanctum::actingAs($this->operator);
    expect($free())->toBe(0)->and($free("&except={$appointment->public_id}"))->toBe(1);

    // Another organization's appointment is not known here
    [$globex] = tenantWithOwner('globex', 'owner@globex.test');
    $theirs = app(TenantContext::class)->run($globex, fn () => Appointment::factory()->create([
        'tenant_id' => $globex->id,
        'location_id' => AppointmentLocation::factory()->create(['tenant_id' => $globex->id])->id,
        'cardholder_id' => Cardholder::factory()->create(['tenant_id' => $globex->id])->id,
    ]));
    $this->getJson("{$url}&except={$theirs->public_id}")->assertUnprocessable()->assertJsonValidationErrors('except');
});
