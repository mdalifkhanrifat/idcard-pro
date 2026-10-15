<?php

use App\Data\Notifications\ChannelReceipt;
use App\Data\Notifications\NotificationRecipient;
use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationMessage;
use App\Models\Card;
use App\Models\NotificationMessage;
use App\Services\Notifications\Channels\NotificationChannel;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\Notifications\NotificationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['name' => 'Acme Ltd']);
    Sanctum::actingAs($this->owner);
    $this->smsFile = sys_get_temp_dir().'/sms-test-'.uniqid().'.log';
    config(['logging.channels.sms' => ['driver' => 'single', 'path' => $this->smsFile, 'level' => 'info']]);
    $template = cardTemplate($this->tenant, ['code' => 'EMP']);
    $this->jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'email' => 'jane@example.com', 'personal_phone' => '+8801711000000']);
    $issued = $this->postJson("/api/v1/cardholders/{$this->jane->public_id}/cards", ['template' => $template->public_id, 'reason' => 'new'])->json('data');
    $this->card = app(TenantContext::class)->run($this->tenant, fn () => Card::query()->where('public_id', $issued['id'])->firstOrFail());
    $this->choose = function (array $notifications): void {
        $this->tenant->forceFill(['settings' => [...($this->tenant->settings ?? []), 'notifications' => $notifications]])->save();
    };
    $this->notify = fn () => app(TenantContext::class)->run($this->tenant->fresh(), fn () => app(NotificationService::class)->notify('card_ready', $this->card));
    $this->mails = fn () => app('mailer')->getSymfonyTransport()->messages();
    $this->texts = fn () => file_exists($this->smsFile) ? substr_count((string) file_get_contents($this->smsFile), 'SMS {') : 0;
});

afterEach(function () {
    @unlink($this->smsFile);
});

it('sends nothing, and logs nothing, while notifications or the event are off', function () {
    expect(($this->notify)())->toBeEmpty();
    ($this->choose)(['enabled' => true, 'channels' => ['mail', 'sms'], 'events' => ['card_ready' => ['enabled' => false, 'channels' => ['mail', 'sms']]]]);
    expect(($this->notify)())->toBeEmpty();
    ($this->choose)(['enabled' => false, 'channels' => ['mail', 'sms'], 'events' => ['card_ready' => ['enabled' => true, 'channels' => ['mail', 'sms']]]]);

    expect(($this->notify)())->toBeEmpty()
        ->and(NotificationMessage::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(($this->mails)())->toHaveCount(0)
        ->and(($this->texts)())->toBe(0);
});

it('sends on exactly the channels the organization and the event both use', function (array $organization, array $event, array $expected) {
    ($this->choose)(['enabled' => true, 'channels' => $organization, 'events' => ['card_ready' => ['enabled' => true, 'channels' => $event]]]);

    $messages = ($this->notify)();

    expect($messages->pluck('channel')->all())->toBe($expected)
        ->and($messages->every(fn (NotificationMessage $message) => $message->fresh()->status === NotificationStatus::Sent))->toBeTrue()
        ->and(($this->mails)())->toHaveCount(in_array('mail', $expected, true) ? 1 : 0)
        ->and(($this->texts)())->toBe(in_array('sms', $expected, true) ? 1 : 0);
})->with([
    'mail only' => [['mail'], ['mail', 'sms'], ['mail']],
    'SMS only' => [['sms'], ['mail', 'sms'], ['sms']],
    'both' => [['mail', 'sms'], ['mail', 'sms'], ['mail', 'sms']],
    'the event on SMS only' => [['mail', 'sms'], ['sms'], ['sms']],
    'in the organization\'s order' => [['sms', 'mail'], ['mail', 'sms'], ['sms', 'mail']],
    'no channel in common' => [['mail'], ['sms'], []],
]);

it('logs each message with whom, what about and the words as sent, in the organization\'s languages', function () {
    ($this->choose)(['enabled' => true, 'channels' => ['mail', 'sms'], 'languages' => ['en'], 'events' => ['card_ready' => ['enabled' => true, 'channels' => ['mail', 'sms']]]]);

    [$mail, $sms] = ($this->notify)()->map->fresh()->all();

    expect($mail->only(['event', 'channel', 'address', 'cardholder_id', 'card_id', 'languages', 'subject', 'attempts', 'driver', 'sent_by']))->toBe([
        'event' => 'card_ready', 'channel' => 'mail', 'address' => 'jane@example.com', 'cardholder_id' => $this->jane->id, 'card_id' => $this->card->id,
        'languages' => 'en', 'subject' => "Your ID card {$this->card->number} is ready", 'attempts' => 1, 'driver' => 'array', 'sent_by' => null,
    ])->and($mail->sent_at)->not->toBeNull()
        ->and($mail->provider_id)->not->toBeEmpty()
        ->and($sms->only(['channel', 'address', 'subject', 'driver']))->toBe(['channel' => 'sms', 'address' => '+8801711000000', 'subject' => null, 'driver' => 'log'])
        // A card without an expiry date says so in a whole sentence (no empty date)
        ->and($sms->body)->toBe("Acme Ltd: your ID card {$this->card->number} is ready. It has no expiry date.")
        ->and($sms->provider_id)->toStartWith('log-');

    // Both languages: one message each, Bangla first
    ($this->choose)(['enabled' => true, 'channels' => ['sms'], 'languages' => ['bn', 'en'], 'events' => ['card_ready' => ['enabled' => true, 'channels' => ['sms']]]]);
    expect(($this->notify)()->sole()->fresh()->only(['languages', 'body']))->toMatchArray(['languages' => 'bn,en'])
        ->and(explode("\n", ($this->notify)()->sole()->body))->toHaveCount(2);
});

it('skips a channel the person has no address for, with the reason, and sends the others', function () {
    $this->jane->update(['personal_phone' => null]);
    ($this->choose)(['enabled' => true, 'channels' => ['mail', 'sms'], 'events' => ['card_ready' => ['enabled' => true, 'channels' => ['mail', 'sms']]]]);

    [$mail, $sms] = ($this->notify)()->map->fresh()->all();

    expect($mail->status)->toBe(NotificationStatus::Sent)
        ->and($sms->status)->toBe(NotificationStatus::Skipped)
        ->and($sms->reason)->toBe('no_address')
        ->and($sms->address)->toBeNull()
        ->and($sms->attempts)->toBe(0)
        ->and(($this->texts)())->toBe(0);
});

it('hands messages to the queue only once what caused them is saved, one job per message', function () {
    Queue::fake();
    ($this->choose)(['enabled' => true, 'channels' => ['mail', 'sms'], 'events' => ['card_ready' => ['enabled' => true, 'channels' => ['mail', 'sms']]]]);

    $messages = ($this->notify)();

    Queue::assertPushed(SendNotificationMessage::class, 2);
    $job = new SendNotificationMessage($messages->first()->id);
    expect($job->uniqueId())->toBe((string) $messages->first()->id)
        ->and($job->tries())->toBe(4)
        ->and($job->backoff())->toBe([60, 300, 900])
        ->and($messages->every(fn (NotificationMessage $message) => $message->fresh()->status === NotificationStatus::Queued))->toBeTrue();
});

it('keeps the error of a failed attempt, tries again, and marks the message failed after the last try', function () {
    Queue::fake();
    ($this->choose)(['enabled' => true, 'channels' => ['mail'], 'events' => ['card_ready' => ['enabled' => true, 'channels' => ['mail']]]]);
    $message = ($this->notify)()->sole();
    $failing = new class implements NotificationChannel
    {
        public function send(NotificationMessage $message): ChannelReceipt
        {
            throw new RuntimeException('Mail server unreachable');
        }
    };
    app()->instance('test.failing-channel', $failing);
    config(['notifications.channels.mail.class' => 'test.failing-channel']);
    $delivery = app(NotificationDeliveryService::class);

    expect(fn () => $delivery->deliver($message->id))->toThrow(RuntimeException::class, 'Mail server unreachable');
    expect($message->fresh()->only(['status', 'attempts', 'error']))->toBe(['status' => NotificationStatus::Queued, 'attempts' => 1, 'error' => 'Mail server unreachable']);
    expect(fn () => $delivery->deliver($message->id))->toThrow(RuntimeException::class);

    (new SendNotificationMessage($message->id))->failed(new RuntimeException('Mail server unreachable'));
    expect($message->fresh()->status)->toBe(NotificationStatus::Failed)
        ->and($message->fresh()->attempts)->toBe(2)
        ->and($message->fresh()->failed_at)->not->toBeNull();
});

it('never sends a message twice, and skips an event\'s message the organization turned off while it waited', function () {
    Queue::fake();
    ($this->choose)(['enabled' => true, 'channels' => ['mail', 'sms'], 'events' => ['card_ready' => ['enabled' => true, 'channels' => ['mail', 'sms']]]]);
    [$mail, $sms] = ($this->notify)()->all();
    $delivery = app(NotificationDeliveryService::class);

    $delivery->deliver($mail->id);
    $delivery->deliver($mail->id);
    expect(($this->mails)())->toHaveCount(1)
        ->and($mail->fresh()->attempts)->toBe(1);

    // SMS turned off for the event before its turn came
    ($this->choose)(['enabled' => true, 'channels' => ['mail', 'sms'], 'events' => ['card_ready' => ['enabled' => true, 'channels' => ['mail']]]]);
    $delivery->deliver($sms->id);
    expect($sms->fresh()->only(['status', 'reason', 'attempts']))->toBe(['status' => NotificationStatus::Skipped, 'reason' => 'turned_off', 'attempts' => 0])
        ->and(($this->texts)())->toBe(0);

    // A message someone sent by hand goes as asked, whatever the event's settings
    ($this->choose)(['enabled' => false]);
    $byHand = app(TenantContext::class)->run($this->tenant->fresh(), fn () => app(NotificationService::class)->queue(
        ['event' => 'test', 'channel' => 'sms', 'languages' => 'en', 'subject' => null, 'body' => 'Test'],
        new NotificationRecipient('Owner', phone: '+8801800000000', userId: $this->owner->id),
        $this->owner,
    ));
    $delivery->deliver($byHand->id);
    expect($byHand->fresh()->only(['status', 'user_id', 'sent_by']))->toBe(['status' => NotificationStatus::Sent, 'user_id' => $this->owner->id, 'sent_by' => $this->owner->id])
        ->and(($this->texts)())->toBe(1);
});

it('writes an SMS in the first language only when the organization chooses so, an e-mail still in all of them', function () {
    ($this->choose)(['enabled' => true, 'channels' => ['mail', 'sms'], 'languages' => ['bn', 'en'], 'sms_languages' => 'first', 'events' => ['card_ready' => ['enabled' => true, 'channels' => ['mail', 'sms']]]]);

    [$mail, $sms] = ($this->notify)()->map->fresh()->all();

    expect($mail->languages)->toBe('bn,en')
        ->and($mail->body)->toContain('প্রিয় Jane Doe,')->toContain('Dear Jane Doe,')
        ->and($sms->languages)->toBe('bn')
        ->and($sms->body)->toBe("Acme Ltd: আপনার কার্ড {$this->card->number} তৈরি, সংগ্রহ করুন।");

    // All languages (the default): the SMS has both, in order
    ($this->choose)(['enabled' => true, 'channels' => ['sms'], 'languages' => ['en', 'bn'], 'events' => ['card_ready' => ['enabled' => true, 'channels' => ['sms']]]]);
    expect(($this->notify)()->sole()->fresh()->languages)->toBe('en,bn');
});
