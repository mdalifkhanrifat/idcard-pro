<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\NotificationMessage;
use App\Services\Notifications\Channels\MailChannel;
use App\Services\Notifications\NotificationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| Messages an appointment's changes send (5.7a), once saved, as the organization's notification settings say:
| booked, moved (not a note alone), confirmed (approved, or a cancelled one restored), cancelled or turned down —
| to the cardholder, also for a family member's booking; steps of the visit and other corrections send nothing;
| a message that cannot be logged never undoes the booking. 16 October 2026 is a Friday in Dhaka (09:00); the
| places are open Sunday to Thursday, 9 to 5.
*/

beforeEach(function () {
    Queue::fake();
    $this->withoutMiddleware(ThrottleRequests::class);
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->operator = tenantUser($this->tenant, 'Operator');
    $this->settings = function (array $appointments = [], array $notifications = []): void {
        $this->tenant->forceFill(['timezone' => 'Asia/Dhaka', 'locale' => 'en', 'settings' => [
            'appointments' => ['enabled' => true, 'slot_minutes' => 30, 'confirmation' => 'approval', ...$appointments],
            // Each event is off until the organization turns it on
            'notifications' => ['enabled' => true, 'channels' => ['mail', 'sms'], 'languages' => ['en'], 'events' => array_fill_keys(
                ['appointment_booked', 'appointment_confirmed', 'appointment_changed', 'appointment_cancelled'], ['enabled' => true, 'channels' => ['mail', 'sms']],
            ), ...$notifications],
        ]])->save();
        $this->owner->unsetRelation('tenant');
        $this->operator->unsetRelation('tenant');
    };
    ($this->settings)();
    $in = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    $this->place = $in(fn () => AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Head office', 'slot_capacity' => 2]));
    $this->holder = $in(fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id, 'full_name' => 'Jane Doe', 'email' => 'jane@example.com', 'personal_phone' => '+8801711000000']));
    $this->son = $in(fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id, 'name' => 'Sam Doe']));
    $this->travelTo(now()->parse('2026-10-16 03:00:00'));

    $this->book = fn (array $values = []) => $this->postJson('/api/v1/appointments', [
        'location' => $this->place->public_id, 'service' => 'new_card', 'cardholder' => $this->holder->public_id, 'date' => '2026-10-18', 'time' => '10:00', ...$values,
    ]);
    $this->step = fn (string $id, string $status, array $more = []) => $this->postJson("/api/v1/appointments/{$id}/status", ['status' => $status, ...$more]);
    // What was sent since the last look: [event, channel, address]
    $this->seen = 0;
    $this->sent = function (): array {
        $messages = NotificationMessage::query()->withoutGlobalScopes()->where('id', '>', $this->seen)->orderBy('id')->get();
        $this->seen = (int) ($messages->last()->id ?? $this->seen);

        return $messages->map(fn (NotificationMessage $m) => [$m->event, $m->channel, $m->address])->all();
    };
    $this->both = fn (string $event) => [[$event, 'mail', 'jane@example.com'], [$event, 'sms', '+8801711000000']];
});

it('tells the cardholder of a booking, a move, a cancel and a restore — not of a note or the visit\'s steps', function () {
    Sanctum::actingAs($this->owner);
    $booked = ($this->book)(['family_member' => $this->son->public_id])->assertCreated()->json('data');
    expect(($this->sent)())->toBe(($this->both)('appointment_booked'));
    $message = NotificationMessage::query()->withoutGlobalScopes()->where('event', 'appointment_booked')->where('channel', 'mail')->sole();
    expect($message->appointment_id)->toBe(Appointment::query()->withoutGlobalScopes()->where('public_id', $booked['id'])->value('id'))
        ->and($message->subject)->toBe('Your appointment '.$booked['number'].' on 18/10/2026 at 10:00')
        ->and($message->body)->toContain('Dear Sam Doe', '(Confirmed)')
        ->and($message->status->value)->toBe('queued');

    // A note alone; then a move
    $this->patchJson("/api/v1/appointments/{$booked['id']}", ['note' => 'Bring the old card'])->assertOk();
    expect(($this->sent)())->toBe([]);
    $this->patchJson("/api/v1/appointments/{$booked['id']}", ['time' => '11:00'])->assertOk();
    expect(($this->sent)())->toBe(($this->both)('appointment_changed'))
        ->and(NotificationMessage::query()->withoutGlobalScopes()->where('event', 'appointment_changed')->where('channel', 'sms')->value('body'))->toContain('18/10/2026 at 11:00');

    // Cancelled with a reason, restored by a correction; then the visit's own steps and their corrections
    $this->postJson("/api/v1/appointments/{$booked['id']}/cancel", ['reason' => 'Travelling'])->assertOk();
    expect(($this->sent)())->toBe(($this->both)('appointment_cancelled'))
        ->and(NotificationMessage::query()->withoutGlobalScopes()->where('event', 'appointment_cancelled')->where('channel', 'mail')->value('body'))->toContain('Reason: Travelling');
    ($this->step)($booked['id'], 'confirmed')->assertOk();
    expect(($this->sent)())->toBe(($this->both)('appointment_confirmed'));
    $this->travelTo(now()->parse('2026-10-18 05:15:00'));
    ($this->step)($booked['id'], 'checked_in')->assertOk();
    ($this->step)($booked['id'], 'done')->assertOk();
    ($this->step)($booked['id'], 'checked_in')->assertOk();
    ($this->step)($booked['id'], 'confirmed')->assertOk();
    expect(($this->sent)())->toBe([]);
});

it('tells a person waiting for approval when it is confirmed, and when it is turned down', function () {
    Sanctum::actingAs($this->operator);
    $first = ($this->book)()->assertCreated()->json('data');
    $second = ($this->book)(['service' => 'renewal', 'time' => '11:00'])->assertCreated()->json('data');
    expect([$first['status']['key'], $second['status']['key']])->toBe(['pending', 'pending'])
        ->and(($this->sent)())->toBe([...($this->both)('appointment_booked'), ...($this->both)('appointment_booked')])
        ->and(NotificationMessage::query()->withoutGlobalScopes()->where('event', 'appointment_booked')->value('body'))->toContain('(Waiting for approval)');

    Sanctum::actingAs($this->owner);
    ($this->step)($first['id'], 'confirmed')->assertOk();
    ($this->step)($second['id'], 'cancelled', ['reason' => 'No slots for renewals this week'])->assertOk();
    expect(($this->sent)())->toBe([...($this->both)('appointment_confirmed'), ...($this->both)('appointment_cancelled')])
        ->and(NotificationMessage::query()->withoutGlobalScopes()->where('event', 'appointment_confirmed')->where('channel', 'mail')->value('subject'))->toBe("Your appointment {$first['number']} is confirmed");
});

it('sends nothing while notifications or the event are off, or when the booking is refused', function () {
    Sanctum::actingAs($this->owner);
    ($this->settings)([], ['enabled' => false]);
    ($this->book)()->assertCreated();
    ($this->settings)([], ['events' => ['appointment_booked' => ['enabled' => false, 'channels' => ['mail', 'sms']]]]);
    ($this->book)(['service' => 'renewal'])->assertCreated();
    // Refused (the same kind of visit is open already): nothing saved, nothing sent
    ($this->book)(['time' => '12:00'])->assertUnprocessable();
    expect(($this->sent)())->toBe([]);
    // Only one channel on
    ($this->settings)([], ['channels' => ['sms']]);
    ($this->book)(['service' => 'card_collection', 'time' => '13:00'])->assertCreated();
    expect(($this->sent)())->toBe([['appointment_booked', 'sms', '+8801711000000']]);
});

it('keeps the booking when its message cannot be logged', function () {
    Exceptions::fake();
    $this->mock(NotificationService::class)->shouldReceive('notify')->andThrow(new RuntimeException('Message log down'));
    Sanctum::actingAs($this->owner);

    ($this->book)()->assertCreated()->assertJsonPath('data.status.key', AppointmentStatus::Confirmed->value);
    expect(Appointment::query()->withoutGlobalScopes()->count())->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $failure) => $failure->getMessage() === 'Message log down');
});

it('e-mails a calendar file and the number as a QR code, updating the same calendar event on a change', function () {
    Sanctum::actingAs($this->owner);
    $booked = ($this->book)()->assertCreated()->json('data');
    $id = Appointment::query()->withoutGlobalScopes()->where('public_id', $booked['id'])->value('id');
    // Sends the latest e-mail of an event; what it carried
    $mail = function (string $event) use ($id): array {
        $message = NotificationMessage::query()->withoutGlobalScopes()->where('appointment_id', $id)->where('event', $event)->where('channel', 'mail')->latest('id')->firstOrFail();
        app(MailChannel::class)->send($message);
        $email = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $parts = collect($email->getAttachments())->keyBy(fn ($part) => $part->getFilename());

        return ['html' => $email->getHtmlBody(), 'files' => $parts->keys()->all(), 'ics' => str_replace("\r\n ", '', (string) $parts->first(fn ($part, $name) => str_ends_with((string) $name, '.ics'))?->getBody()), 'png' => (string) $parts->get('qr.png')?->getBody()];
    };

    $sent = $mail('appointment_booked');
    expect($sent['files'])->toBe(['qr.png', "appointment-{$booked['number']}.ics"])
        ->and(substr($sent['png'], 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and($sent['html'])->toMatch('/<img src="cid:[^"]+"/')->toContain("Show this QR code ({$booked['number']}) at the desk.")
        ->and($sent['ics'])->toContain("UID:{$booked['id']}@localhost\r\n", "DTSTART:20261018T040000Z\r\nDTEND:20261018T043000Z\r\n", "SUMMARY:Appointment: New card — Acme\r\n", 'LOCATION:Head office', "STATUS:CONFIRMED\r\n");
    preg_match('/SEQUENCE:(\d+)/', $sent['ics'], $first);

    $this->travel(5)->minutes();
    $this->postJson("/api/v1/appointments/{$booked['id']}/cancel")->assertOk();
    $cancelled = $mail('appointment_cancelled');
    preg_match('/SEQUENCE:(\d+)/', $cancelled['ics'], $later);
    // The same event, cancelled; no QR code — there is no visit
    expect($cancelled['files'])->toBe(["appointment-{$booked['number']}.ics"])
        ->and($cancelled['ics'])->toContain("UID:{$booked['id']}@localhost\r\n", "STATUS:CANCELLED\r\n", 'is cancelled.')
        ->and((int) $later[1])->toBeGreaterThan((int) $first[1])
        ->and($cancelled['html'])->not->toContain('cid:');

    // The organization may leave both out
    ($this->settings)(['messages' => ['calendar' => false, 'qr' => false]]);
    $this->owner->unsetRelation('tenant');
    ($this->step)($booked['id'], 'confirmed')->assertOk();
    expect($mail('appointment_confirmed')['files'])->toBe([]);
});

it('puts the public booking page in the e-mails while it is open, saying what the person may do there', function () {
    Sanctum::actingAs($this->owner);
    config(['app.url' => 'https://idcard.example/']);
    $all = ['appointment_booked', 'appointment_confirmed', 'appointment_changed', 'appointment_cancelled'];
    $events = fn (array $changed = []) => ['events' => [...array_fill_keys($all, ['enabled' => true, 'channels' => ['mail', 'sms']]), 'appointment_changed' => ['enabled' => true, 'channels' => ['mail', 'sms'], ...$changed]]];
    // The latest message of an event on a channel: its words
    $body = fn (string $event, string $channel = 'mail') => (string) NotificationMessage::query()->withoutGlobalScopes()->where('event', $event)->where('channel', $channel)->latest('id')->value('body');

    // The page is off: no sentence, nothing left of it
    $first = ($this->book)()->assertCreated()->json('data');
    expect($body('appointment_booked'))->not->toContain('/book/')->toContain("please have it with you.\n\nAcme");

    // Open: on a cancel, where to book again; on a booking, moving and cancelling as the settings allow
    ($this->settings)(['booking' => ['staff', 'public']], $events());
    $this->postJson("/api/v1/appointments/{$first['id']}/cancel")->assertOk();
    expect($body('appointment_cancelled'))->toContain('You are welcome to book another time. Book online: https://idcard.example/book/acme');
    $booked = ($this->book)()->assertCreated()->json('data');
    expect($body('appointment_booked'))->toContain('please have it with you. To move or cancel it yourself: https://idcard.example/book/acme')
        // Not in an SMS by default: it would no longer fit one part
        ->and($body('appointment_booked', 'sms'))->not->toContain('/book/');

    // A link on this application's own page in the e-mail; another address stays text
    $message = NotificationMessage::query()->withoutGlobalScopes()->where('event', 'appointment_booked')->where('channel', 'mail')->latest('id')->firstOrFail();
    $message->forceFill(['body' => $message->body."\n\nSee https://elsewhere.example/book/acme."])->save();
    app(MailChannel::class)->send($message);
    $html = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHtmlBody();
    expect($html)->toContain('<a href="https://idcard.example/book/acme" style="color:#0f766e;word-break:break-all;">https://idcard.example/book/acme</a>')
        ->not->toContain('<a href="https://elsewhere');

    // Only cancelling allowed: it says so
    ($this->settings)(['booking' => ['staff', 'public'], 'changes' => ['cancel' => true, 'reschedule' => false]], $events());
    $this->patchJson("/api/v1/appointments/{$booked['id']}", ['time' => '11:00'])->assertOk();
    expect($body('appointment_changed'))->toEndWith("at Head office. To cancel it yourself: https://idcard.example/book/acme\n\nAcme");
    // Neither: where to see it
    ($this->settings)(['booking' => ['staff', 'public'], 'changes' => ['cancel' => false, 'reschedule' => false]], $events());
    $this->patchJson("/api/v1/appointments/{$booked['id']}", ['time' => '11:30'])->assertOk();
    expect($body('appointment_changed'))->toContain('To see your appointments: https://idcard.example/book/acme');

    // The organization leaves it out of one event; the others keep it
    ($this->settings)(['booking' => ['staff', 'public']], $events(['options' => ['link' => 'leave_out']]));
    $this->patchJson("/api/v1/appointments/{$booked['id']}", ['time' => '12:00'])->assertOk();
    $this->postJson("/api/v1/appointments/{$booked['id']}/cancel")->assertOk();
    expect($body('appointment_changed'))->not->toContain('/book/')
        ->and($body('appointment_cancelled'))->toContain('Book online: https://idcard.example/book/acme');

    // In Bangla too
    ($this->settings)(['booking' => ['staff', 'public']], ['languages' => ['bn'], ...$events()]);
    ($this->book)()->assertCreated();
    expect($body('appointment_booked'))->toContain('সাথে রাখুন। নিজে সরাতে বা বাতিল করতে: https://idcard.example/book/acme');
});
