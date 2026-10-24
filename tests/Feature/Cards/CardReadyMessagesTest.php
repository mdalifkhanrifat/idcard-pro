<?php

use App\Data\Cards\ImportCardData;
use App\Models\FamilyMember;
use App\Models\NotificationMessage;
use App\Services\Cards\CardIssueService;
use App\Services\Notifications\NotificationService;
use App\Support\Pdf\PdfOptions;
use App\Support\Pdf\PdfRenderer;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| "Card ready" (5.7d): once per card, when it is first printed — a print the user confirmed, or a server PDF
| recorded as printed — or, as the organization chooses (the event's own setting `send_on`), when it is issued.
| Printing again sends nothing, importing a card neither; to the cardholder, also for a family member's card;
| off unless the organization turned the event on; a message that cannot be logged never undoes the print.
*/

beforeEach(function () {
    Queue::fake();
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->settings = function (array $cardReady = ['enabled' => true, 'channels' => ['mail', 'sms']]): void {
        $this->tenant->forceFill(['timezone' => 'Asia/Dhaka', 'locale' => 'en', 'settings' => [
            'notifications' => ['enabled' => true, 'channels' => ['mail', 'sms'], 'languages' => ['en'], 'events' => ['card_ready' => $cardReady]],
        ]])->save();
        $this->owner->unsetRelation('tenant');
    };
    ($this->settings)();
    $this->holder = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'email' => 'jane@example.com', 'personal_phone' => '+8801711000000']);
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP']);
    $this->issue = fn (array $extra = []) => $this->postJson("/api/v1/cardholders/{$this->holder->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new', ...$extra])->assertCreated()->json('data');
    $this->print = fn (string ...$ids) => count($ids) === 1
        ? $this->postJson("/api/v1/cards/{$ids[0]}/prints")->assertCreated()
        : $this->postJson('/api/v1/cards/prints', ['cards' => $ids])->assertCreated();
    $this->app->instance(PdfRenderer::class, new class implements PdfRenderer
    {
        public function renderUrl(string $url, PdfOptions $options = new PdfOptions): string
        {
            return '%PDF-1.7 cards';
        }
    });
    // What was sent since the last look: [event, channel, address, card number]
    $this->seen = 0;
    $this->sent = function (): array {
        $messages = NotificationMessage::query()->withoutGlobalScopes()->with('card')->where('id', '>', $this->seen)->orderBy('id')->get();
        $this->seen = (int) ($messages->last()->id ?? $this->seen);

        return $messages->map(fn (NotificationMessage $m) => [$m->event, $m->channel, $m->address, $m->card?->number])->all();
    };
    $this->ready = fn (string $number) => [['card_ready', 'mail', 'jane@example.com', $number], ['card_ready', 'sms', '+8801711000000', $number]];
    Sanctum::actingAs($this->owner);
});

it('tells the holder once, when the card is first printed: not on issue, not when printed again', function () {
    $card = ($this->issue)();
    expect(($this->sent)())->toBe([]);

    ($this->print)($card['id']);
    expect(($this->sent)())->toBe(($this->ready)($card['number']));
    $message = NotificationMessage::query()->withoutGlobalScopes()->where('channel', 'mail')->sole();
    expect($message->subject)->toBe("Your ID card {$card['number']} is ready")
        ->and($message->cardholder_id)->toBe($this->holder->id);

    // After a printer jam, and as a PDF recorded as printed: it was ready already
    ($this->print)($card['id']);
    $this->get("/api/v1/cards/{$card['id']}/pdf?record=1")->assertOk();
    expect(($this->sent)())->toBe([]);

    // A new card (renewal) is ready again once printed
    $renewed = ($this->issue)(['reason' => 'renewal']);
    ($this->print)($renewed['id']);
    expect(($this->sent)())->toBe(($this->ready)($renewed['number']));
});

it('tells each card of a batch printed for the first time, and a card printed first as a recorded PDF', function () {
    $son = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id, 'name' => 'Sam Doe']));
    $family = cardTemplate($this->tenant, ['code' => 'FAM', 'audience' => 'family_member']);
    $printed = ($this->issue)();
    ($this->print)($printed['id']);
    ($this->sent)();
    $sons = ($this->issue)(['template' => $family->public_id, 'family_member' => $son->public_id]);

    // The family member's card goes to the cardholder, in the holder's name; the card printed before sends nothing
    ($this->print)($printed['id'], $sons['id']);
    expect(($this->sent)())->toBe(($this->ready)($sons['number']))
        ->and(NotificationMessage::query()->withoutGlobalScopes()->where('channel', 'mail')->latest('id')->first()->body)->toContain('Dear Sam Doe');

    // A PDF only downloaded is not a printing; recorded, it is
    $pdf = ($this->issue)(['reason' => 'renewal']);
    $this->get("/api/v1/cards/{$pdf['id']}/pdf")->assertOk();
    expect(($this->sent)())->toBe([]);
    $this->postJson('/api/v1/cards/pdf', ['cards' => [$pdf['id']], 'record' => true])->assertOk();
    expect(($this->sent)())->toBe(($this->ready)($pdf['number']));
});

it('tells the holder on issue instead, when the organization chooses so, and sends nothing on printing then', function () {
    ($this->settings)(['enabled' => true, 'channels' => ['sms'], 'options' => ['send_on' => 'issue']]);

    $card = ($this->issue)();
    expect(($this->sent)())->toBe([['card_ready', 'sms', '+8801711000000', $card['number']]]);
    ($this->print)($card['id']);
    expect(($this->sent)())->toBe([]);
});

it('sends nothing for imported cards, or while the event or notifications are off', function () {
    $in = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant->fresh(), $callback);
    foreach (['print', 'issue'] as $sendOn) {
        ($this->settings)(['enabled' => true, 'channels' => ['mail', 'sms'], 'options' => ['send_on' => $sendOn]]);
        $holder = cardholder($this->tenant, ['email' => "{$sendOn}@example.com"]);
        $imported = $in(fn () => app(CardIssueService::class)->importCard($holder, $this->template->fresh(), new ImportCardData(
            issueDate: CarbonImmutable::parse('2026-01-01'),
            lastPrintedAt: CarbonImmutable::parse('2026-01-02 10:00:00'),
        ), $this->owner));
        // Printed before it came in: printing it here again is not its first printing
        ($this->print)($imported->public_id);
        expect(($this->sent)())->toBe([]);
    }

    ($this->settings)(['enabled' => false, 'channels' => ['mail', 'sms']]);
    ($this->print)(($this->issue)(['reason' => 'renewal'])['id']);
    $this->tenant->forceFill(['settings' => ['notifications' => ['enabled' => false, 'events' => ['card_ready' => ['enabled' => true, 'channels' => ['mail']]]]]])->save();
    $this->owner->unsetRelation('tenant');
    ($this->print)(($this->issue)(['reason' => 'renewal'])['id']);
    expect(($this->sent)())->toBe([]);
});

it('keeps the print when its message cannot be logged', function () {
    Exceptions::fake();
    $this->mock(NotificationService::class)->shouldReceive('notify')->andThrow(new RuntimeException('Log table is gone'));

    $card = ($this->issue)();
    ($this->print)($card['id']);

    expect($this->getJson("/api/v1/cards/{$card['id']}")->json('data.print_count'))->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Log table is gone');
});
