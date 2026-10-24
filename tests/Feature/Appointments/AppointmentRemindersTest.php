<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\AppointmentReminder;
use App\Models\Cardholder;
use App\Models\NotificationMessage;
use App\Repositories\Contracts\AppointmentReminderRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/*
| Reminders before appointments (5.7c, app:send-appointment-reminders every five minutes): the organization's
| hours before a confirmed appointment's start, once each, within the grace, not when the person heard of it after
| that moment, a whole day from when its place opens, the nearest only when several are due; nothing while the
| module, the reminders or the event are off. Times are UTC; the organization is in Dhaka (UTC+6); places open
| Sunday to Thursday, 9 to 5; Sunday 18 October 2026 10:00 Dhaka is 04:00 UTC.
*/

beforeEach(function () {
    Queue::fake();
    [$this->tenant] = tenantWithOwner();
    $this->settings = function (array $appointments = [], array $reminder = ['enabled' => true, 'channels' => ['mail']]): void {
        $this->tenant->forceFill(['timezone' => 'Asia/Dhaka', 'locale' => 'en', 'settings' => [
            'appointments' => ['enabled' => true, 'reminders' => [24, 2], ...$appointments],
            'notifications' => ['enabled' => true, 'channels' => ['mail'], 'languages' => ['en'], 'events' => ['appointment_reminder' => $reminder]],
        ]])->save();
    };
    ($this->settings)();
    $this->in = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    $this->place = ($this->in)(fn () => AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Head office']));
    $this->holder = ($this->in)(fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'jane@example.com']));
    // Booked on Thursday 15 October unless said otherwise
    $this->booking = function (string $day, ?string $startsAtUtc, array $more = [], string $bookedAt = '2026-10-15 03:00:00'): Appointment {
        $this->travelTo(now()->parse($bookedAt));
        $factory = Appointment::factory();
        $factory = $startsAtUtc === null ? $factory->state(['date' => $day, 'starts_at' => null, 'ends_at' => null]) : $factory->at($day, $startsAtUtc, 30);

        return ($this->in)(fn () => $factory->create(['tenant_id' => $this->tenant->id, 'location_id' => $this->place->id, 'cardholder_id' => $this->holder->id, 'status' => AppointmentStatus::Confirmed, ...$more]));
    };
    // Runs as the scheduler would at a moment (UTC); the reminders sent, as [number, hours before]
    $this->run = function (string $at): array {
        $this->travelTo(now()->parse($at));
        $this->artisan('app:send-appointment-reminders')->assertSuccessful();

        return NotificationMessage::query()->withoutGlobalScopes()->where('event', 'appointment_reminder')->where('created_at', '>=', now()->subSecond())
            ->with('appointment')->get()->map(fn (NotificationMessage $m) => $m->appointment->number)->all();
    };
});

it('reminds the organization\'s hours before, once each, and again for a moved appointment', function () {
    $sunday = ($this->booking)('2026-10-18', '2026-10-18 04:00:00', ['number' => 'A-26-00001']);

    expect(($this->run)('2026-10-17 03:55:00'))->toBe([])
        ->and(($this->run)('2026-10-17 04:00:00'))->toBe(['A-26-00001'])
        // Once, also if run again
        ->and(($this->run)('2026-10-17 04:05:00'))->toBe([])
        ->and(($this->run)('2026-10-18 02:00:00'))->toBe(['A-26-00001'])
        ->and(($this->run)('2026-10-18 03:55:00'))->toBe([]);
    $message = NotificationMessage::query()->withoutGlobalScopes()->where('event', 'appointment_reminder')->firstOrFail();
    expect($message->address)->toBe('jane@example.com')
        ->and($message->subject)->toBe('Reminder: your appointment on 18/10/2026 at 10:00');

    // Moved on Sunday 07:00 to Monday 10:00: reminded again for the new time (its 24 hours not yet come)
    $this->travelTo(now()->parse('2026-10-18 01:00:00'));
    ($this->in)(fn () => $sunday->update(['date' => '2026-10-19', 'starts_at' => '2026-10-19 04:00:00', 'ends_at' => '2026-10-19 04:30:00']));
    expect(($this->run)('2026-10-18 04:00:00'))->toBe(['A-26-00001'])
        ->and(($this->run)('2026-10-19 02:00:00'))->toBe(['A-26-00001'])
        ->and(AppointmentReminder::query()->withoutGlobalScopes()->orderBy('id')->pluck('hours')->all())->toBe([24, 2, 24, 2]);
});

it('lets a late one go, skips one the person heard of since, and reminds a whole day from its opening', function () {
    // Due Saturday 06:00, found at 09:00 (past the grace of 2 hours): never sent
    ($this->booking)('2026-10-18', '2026-10-18 06:00:00', ['number' => 'A-26-00002']);
    // Booked on Saturday 05:10, after its 24 hours' moment (05:00): only the 2 hours' reminder
    ($this->booking)('2026-10-18', '2026-10-18 05:00:00', ['number' => 'A-26-00003'], bookedAt: '2026-10-17 05:10:00');
    // A whole Sunday: the place opens at 09:00 Dhaka (03:00 UTC)
    ($this->booking)('2026-10-18', null, ['number' => 'A-26-00004']);

    expect(($this->run)('2026-10-17 03:00:00'))->toBe(['A-26-00004'])
        ->and(($this->run)('2026-10-17 05:15:00'))->toBe([])
        ->and(($this->run)('2026-10-17 09:00:00'))->toBe([])
        ->and(($this->run)('2026-10-18 01:00:00'))->toBe(['A-26-00004'])
        ->and(($this->run)('2026-10-18 03:00:00'))->toBe(['A-26-00003'])
        ->and(($this->run)('2026-10-18 04:00:00'))->toBe(['A-26-00002']);
});

it('sends the nearest only when several are due at once, and nothing for one not confirmed', function () {
    ($this->settings)(['reminders' => [24, 23]]);
    ($this->booking)('2026-10-18', '2026-10-18 04:00:00', ['number' => 'A-26-00005']);
    ($this->booking)('2026-10-18', '2026-10-18 04:30:00', ['number' => 'A-26-00006', 'status' => AppointmentStatus::Pending]);
    ($this->booking)('2026-10-18', '2026-10-18 05:00:00', ['number' => 'A-26-00007', 'status' => AppointmentStatus::Cancelled]);

    expect(($this->run)('2026-10-17 05:30:00'))->toBe(['A-26-00005'])
        ->and(AppointmentReminder::query()->withoutGlobalScopes()->orderBy('hours')->pluck('hours')->all())->toBe([23, 24])
        ->and(($this->run)('2026-10-17 05:35:00'))->toBe([]);
});

it('sends nothing while the module, the reminders or the event are off', function () {
    ($this->booking)('2026-10-18', '2026-10-18 04:00:00');
    foreach ([[['enabled' => false]], [['reminders' => []]], [[], ['enabled' => false, 'channels' => ['mail']]]] as $off) {
        ($this->settings)(...$off);
        $this->travelTo(now()->parse('2026-10-17 04:00:00'));
        $this->artisan('app:send-appointment-reminders')->expectsOutputToContain('No organization sends appointment reminders.')->assertSuccessful();
    }
    expect(AppointmentReminder::query()->withoutGlobalScopes()->count())->toBe(0);
    $this->artisan('app:send-appointment-reminders', ['tenant' => 'nobody'])->assertFailed();
});

it('lets only one of two runs at the same time take a reminder', function () {
    $appointment = ($this->booking)('2026-10-18', '2026-10-18 04:00:00');
    $reminders = app(AppointmentReminderRepositoryInterface::class);
    $due = CarbonImmutable::parse('2026-10-17 04:00:00', 'UTC');

    expect($reminders->claim($appointment, 24, $due))->toBeTrue()
        ->and($reminders->claim($appointment, 24, $due))->toBeFalse()
        ->and($reminders->claim($appointment, 2, $due->addHours(22)))->toBeTrue();
});
