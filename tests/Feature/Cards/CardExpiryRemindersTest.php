<?php

use App\Enums\CardholderStatus;
use App\Models\Card;
use App\Models\FamilyMember;
use App\Models\NotificationMessage;
use App\Models\NotificationOnce;
use App\Repositories\Contracts\NotificationOnceRepositoryInterface;
use App\Services\Cards\CardExpiryReminderService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

/*
| Reminders before a card's last day (5.7d): every hour, from 9 in the organization's morning, for each of the
| card_expiring event's days (its own setting) — once, the nearest when several are due, up to two days late;
| only current cards of active cardholders (the Expired report's rule), not one issued after its reminder's day;
| to the cardholder, also for a family member's card. 24 October 2026, 09:00 in Dhaka = 03:00 UTC.
*/

beforeEach(function () {
    Queue::fake();
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->settings = function (array $cardExpiring = ['enabled' => true, 'channels' => ['mail', 'sms'], 'options' => ['days' => [30, 7]]], bool $enabled = true): void {
        $this->tenant->forceFill(['timezone' => 'Asia/Dhaka', 'locale' => 'en', 'settings' => [
            'notifications' => ['enabled' => $enabled, 'channels' => ['mail', 'sms'], 'languages' => ['en'], 'events' => ['card_expiring' => $cardExpiring]],
        ]])->save();
    };
    ($this->settings)();
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP']);
    $this->in = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant->fresh(), $callback);
    // A card of a new holder (one current card each) whose last day is the date given
    $this->card = function (string $lastDay, array $values = [], array $holder = []) {
        $person = cardholder($this->tenant, ['email' => fake()->unique()->userName().'@example.com', 'personal_phone' => null, ...$holder]);

        return ($this->in)(fn () => Card::factory()->create([
            'tenant_id' => $this->tenant->id, 'cardholder_id' => $person->id, 'card_template_id' => $this->template->id,
            'issue_date' => '2024-01-01', 'expiry_date' => $lastDay, ...$values,
        ]));
    };
    // The clock itself moves, so the words (days left) are counted from the same moment
    $this->run = function (string $utc): array {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));

        return app(CardExpiryReminderService::class)->send();
    };
    // Cards reminded since the last look, by number
    $this->seen = 0;
    $this->reminded = function (): array {
        $messages = NotificationMessage::query()->withoutGlobalScopes()->with('card')->where('id', '>', $this->seen)->where('channel', 'mail')->orderBy('id')->get();
        $this->seen = (int) ($messages->last()->id ?? $this->seen);

        return $messages->map(fn (NotificationMessage $m) => $m->card->number)->all();
    };
});

it('reminds once on each of the days before a card\'s last day, in the organization\'s morning', function () {
    $in30 = ($this->card)('2026-11-23');
    $in7 = ($this->card)('2026-10-31');
    $between = ($this->card)('2026-11-10');

    // Before nine in Dhaka: not yet
    expect(($this->run)('2026-10-24 02:59:00'))->toBe(['acme' => 0]);
    expect(($this->run)('2026-10-24 03:00:00'))->toBe(['acme' => 2])
        ->and(($this->reminded)())->toBe([$in30->number, $in7->number]);
    // Every hour after: nothing again
    expect(($this->run)('2026-10-24 04:00:00'))->toBe(['acme' => 0])
        ->and(($this->reminded)())->toBe([]);

    // The words as the holder reads them, days left counted in the organization's days
    $message = NotificationMessage::query()->withoutGlobalScopes()->where('card_id', $in30->id)->where('channel', 'mail')->sole();
    expect($message->subject)->toBe("Your ID card {$in30->number} expires on 23/11/2026")
        ->and($message->body)->toContain('(30 days from today)')
        ->and($message->address)->toBe($in30->cardholder->email);

    // The card in between gets its 7 days' reminder on its day
    ($this->run)('2026-11-03 03:00:00');
    expect(($this->reminded)())->toBe([$between->number])
        ->and(NotificationMessage::query()->withoutGlobalScopes()->where('card_id', $between->id)->where('channel', 'mail')->sole()->body)->toContain('(7 days from today)');
});

it('counts days in the organization\'s calendar on both sides of Dhaka\'s midnight', function () {
    $tomorrowsDue = ($this->card)('2026-11-23');
    $todaysDue = ($this->card)('2026-11-22');

    // 23:59 on the 23rd in Dhaka (17:59 UTC): the 22 November card is 30 days away, the other 31
    ($this->run)('2026-10-23 17:59:00');
    expect(($this->reminded)())->toBe([$todaysDue->number]);
    // 00:05 on the 24th in Dhaka (18:05 UTC on the 23rd): a new day, but before nine
    expect(($this->run)('2026-10-23 18:05:00'))->toBe(['acme' => 0]);
    ($this->run)('2026-10-24 03:00:00');
    expect(($this->reminded)())->toBe([$tomorrowsDue->number]);
});

it('sends one found up to two days late, not older ones, and only the nearest of several due at once', function () {
    ($this->settings)(['enabled' => true, 'channels' => ['mail'], 'options' => ['days' => [30, 8, 7]]]);
    $twoLate = ($this->card)('2026-11-21');   // its 30 days were on the 22nd
    $threeLate = ($this->card)('2026-11-20'); // on the 21st: too late — so turning reminders on does not send to every card inside its days
    $both = ($this->card)('2026-10-31');      // 8 days were yesterday, 7 days today

    ($this->run)('2026-10-24 03:00:00');
    expect(($this->reminded)())->toBe([$twoLate->number, $both->number])
        ->and(NotificationOnce::query()->withoutGlobalScopes()->where('subject_id', $both->id)->pluck('key')->sort()->values()->all())->toBe(['7:2026-10-31', '8:2026-10-31']);
    ($this->run)('2026-10-25 03:00:00');
    expect(($this->reminded)())->toBe([]);
});

it('reminds only of current cards of active cardholders, not of one issued after its reminder\'s day', function () {
    $replaced = ($this->card)('2026-11-23', ['status' => 'replaced', 'active_number_key' => null, 'active_holder_key' => null]);
    $revoked = ($this->card)('2026-11-23', ['status' => 'revoked', 'active_number_key' => null, 'active_holder_key' => null]);
    $leaver = ($this->card)('2026-11-23', holder: ['status' => CardholderStatus::Inactive]);
    $justIssued = ($this->card)('2026-11-23', ['issue_date' => '2026-10-25']);
    $expired = ($this->card)('2026-10-23');
    $noEnd = ($this->card)('2026-11-23', ['expiry_date' => null]);
    // A family member's card: to the cardholder, in the member's name
    $holder = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'email' => 'jane@example.com']);
    $son = ($this->in)(fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $holder->id, 'name' => 'Sam Doe']));
    $sons = ($this->in)(fn () => Card::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $holder->id, 'family_member_id' => $son->id, 'card_template_id' => $this->template->id, 'issue_date' => '2024-01-01', 'expiry_date' => '2026-11-23']));

    ($this->run)('2026-10-24 03:00:00');
    expect(($this->reminded)())->toBe([$sons->number]);
    $message = NotificationMessage::query()->withoutGlobalScopes()->where('card_id', $sons->id)->where('channel', 'mail')->sole();
    expect($message->address)->toBe('jane@example.com')
        ->and($message->body)->toContain('Dear Sam Doe');
});

it('does nothing for an organization that does not send the event, and follows its own days', function () {
    ($this->card)('2026-11-23');
    $in60 = ($this->card)('2026-12-23');

    ($this->settings)(['enabled' => false, 'channels' => ['mail']]);
    expect(($this->run)('2026-10-24 03:00:00'))->toBe([]);
    ($this->settings)(['enabled' => true, 'channels' => ['mail']], enabled: false);
    expect(($this->run)('2026-10-24 03:00:00'))->toBe([]);
    ($this->settings)(['enabled' => true, 'channels' => []]);
    expect(($this->run)('2026-10-24 03:00:00'))->toBe([]);

    ($this->settings)(['enabled' => true, 'channels' => ['mail'], 'options' => ['days' => [60]]]);
    ($this->run)('2026-10-24 03:00:00');
    expect(($this->reminded)())->toBe([$in60->number]);
});

it('lets only one of two runs at the same time send, and runs every hour from the scheduler', function () {
    $card = ($this->card)('2026-11-23');
    $once = app(NotificationOnceRepositoryInterface::class);

    expect($once->claim($this->tenant->id, 'card_expiring', $card->id, '30:2026-11-23'))->toBeTrue()
        ->and($once->claim($this->tenant->id, 'card_expiring', $card->id, '30:2026-11-23'))->toBeFalse()
        ->and(($this->run)('2026-10-24 03:00:00'))->toBe(['acme' => 0]);

    $this->travelTo(CarbonImmutable::parse('2026-10-24 03:00:00', 'UTC'));
    $this->artisan('app:send-card-expiry-reminders', ['tenant' => 'acme'])->expectsOutputToContain('acme: 0 reminders sent.')->assertSuccessful();
    $this->artisan('app:send-card-expiry-reminders', ['tenant' => 'nobody'])->assertFailed();
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'app:send-card-expiry-reminders'));
    expect($event?->expression)->toBe('0 * * * *');
});
