<?php

use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationMessage;
use App\Models\NotificationMessage;
use App\Services\Notifications\Channels\MailChannel;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/*
| A test message (5.2d-4): POST /notifications/test {channel, address}, behind notifications.send. Fixed words in
| the organization's languages and order go to the address typed (else the sender's own), on any channel of the
| registry, whatever the settings (off here); logged as event "test" and noted in the audit log.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['name' => 'Acme Ltd']);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    $this->smsFile = sys_get_temp_dir().'/sms-test-'.uniqid().'.log';
    config(['logging.channels.sms' => ['driver' => 'single', 'path' => $this->smsFile, 'level' => 'info']]);
    $this->send = fn (array $data, array $headers = []) => $this->postJson('/api/v1/notifications/test', $data, $headers);
    $this->stored = fn (string $id) => ($this->in)(fn () => NotificationMessage::query()->where('public_id', $id)->sole());
    $this->languages = function (array $languages): void {
        $this->tenant->forceFill(['settings' => [...($this->tenant->settings ?? []), 'notifications' => ['languages' => $languages]]])->save();
        $this->owner->unsetRelation('tenant');
    };
});

afterEach(function () {
    @unlink($this->smsFile);
});

it('lets the Owner and the Administrator send a test message, not the Viewer or the Operator', function () {
    Queue::fake();
    foreach (['Viewer' => 403, 'Operator' => 403] as $role => $status) {
        Sanctum::actingAs(tenantUser($this->tenant, $role));
        ($this->send)(['channel' => 'mail', 'address' => 'qa@example.test'])->assertStatus($status);
    }
    Sanctum::actingAs(tenantUser($this->tenant, 'Administrator'));
    ($this->send)(['channel' => 'mail', 'address' => 'qa@example.test'])->assertCreated();
    Queue::assertPushed(SendNotificationMessage::class, 1);
});

it('really goes by e-mail and by SMS, though notifications are off, in the organization\'s language', function () {
    Sanctum::actingAs($this->owner);
    expect($this->tenant->notificationSettings()['enabled'])->toBeFalse();

    $mail = ($this->send)(['channel' => 'mail', 'address' => 'qa@example.test'])->assertCreated()->json('data');

    expect($mail)->toMatchArray([
        'event' => ['key' => 'test', 'name' => 'Test message'],
        'channel' => ['key' => 'mail', 'name' => 'E-mail'],
        'address' => 'qa@example.test',
        'languages' => ['bn'],
        'subject' => 'Acme Ltd: পরীক্ষার বার্তা',
        'sent_by' => ['id' => $this->owner->public_id, 'name' => $this->owner->name],
    ])->and($mail['recipient'])->toMatchArray(['type' => 'user', 'id' => $this->owner->public_id])
        ->and($mail['body'])->toContain('এটি Acme Ltd-এর একটি পরীক্ষার বার্তা।')
        ->and(($this->stored)($mail['id'])->status)->toBe(NotificationStatus::Sent);
    $sent = app('mailer')->getSymfonyTransport()->messages();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getOriginalMessage()->getTo()[0]->getAddress())->toBe('qa@example.test');

    $sms = ($this->send)(['channel' => 'sms', 'address' => '+880 1711-000000'])->assertCreated()->json('data');
    expect($sms)->toMatchArray(['address' => '+880 1711-000000', 'subject' => null, 'body' => 'Acme Ltd: এটি একটি পরীক্ষার বার্তা। এই চ্যানেলে বার্তা ঠিকঠাক যাচ্ছে।'])
        ->and(($this->stored)($sms['id'])->status)->toBe(NotificationStatus::Sent)
        ->and(substr_count((string) file_get_contents($this->smsFile), 'SMS {'))->toBe(1);

    // In the log, named as a test message
    $this->getJson('/api/v1/notifications/messages?filter[event]=test')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.events', fn (array $events) => collect($events)->contains(fn (array $e) => $e['key'] === 'test' && $e['name'] === 'Test message'));
});

it('writes in each of the organization\'s languages, in its order', function () {
    Queue::fake();
    Sanctum::actingAs($this->owner);
    ($this->languages)(['en', 'bn']);

    $both = ($this->send)(['channel' => 'mail', 'address' => 'qa@example.test'])->assertCreated()->json('data');
    expect($both['languages'])->toBe(['en', 'bn'])
        ->and($both['subject'])->toBe('Acme Ltd: test message / Acme Ltd: পরীক্ষার বার্তা')
        ->and($both['body'])->toStartWith('This is a test message from Acme Ltd.')->toContain("\n\n— — —\n\nএটি Acme Ltd-এর");

    ($this->languages)(['en']);
    $sms = ($this->send)(['channel' => 'sms', 'address' => '01711000000'])->assertCreated()->json('data');
    expect($sms['languages'])->toBe(['en'])
        ->and($sms['body'])->toBe('Acme Ltd: this is a test message. Messages on this channel are getting through.');
});

it('goes to the sender\'s own address when none is typed, and asks for one when the profile has none', function () {
    Queue::fake();
    Sanctum::actingAs($this->owner);

    ($this->send)(['channel' => 'mail'])->assertCreated()->assertJsonPath('data.address', 'owner@example.com');

    expect($this->owner->phone)->toBeNull();
    ($this->send)(['channel' => 'sms', 'address' => '  '])->assertUnprocessable()
        ->assertJsonPath('errors.address.0', 'Type the address to send the test message to; your own profile has none for this channel.');
    ($this->send)(['channel' => 'sms'], ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors.address.0', 'পরীক্ষার বার্তা কোন ঠিকানায় যাবে লিখুন; আপনার নিজের প্রোফাইলে এই চ্যানেলের ঠিকানা নেই।');

    $this->owner->update(['phone' => '+8801999000111']);
    ($this->send)(['channel' => 'sms'])->assertCreated()->assertJsonPath('data.address', '+8801999000111');
});

it('accepts a channel of the registry only, and an address that looks like the channel\'s kind', function () {
    Queue::fake();
    // Many tries here; the limit has its own test
    $this->withoutMiddleware(ThrottleRequests::class);
    Sanctum::actingAs($this->owner);

    ($this->send)(['channel' => 'whatsapp', 'address' => 'qa@example.test'])->assertUnprocessable()->assertJsonValidationErrors('channel');
    ($this->send)([])->assertUnprocessable()->assertJsonValidationErrors(['channel', 'address']);
    foreach (['not-an-email', 'qa@', '+8801711000000'] as $address) {
        ($this->send)(['channel' => 'mail', 'address' => $address])->assertUnprocessable()->assertJsonValidationErrors('address');
    }
    foreach (['qa@example.test', '12345', 'call me', '+88 01711 000000 ext 5', '1234567890123456'] as $address) {
        ($this->send)(['channel' => 'sms', 'address' => $address])->assertUnprocessable()->assertJsonValidationErrors('address');
    }
    ($this->send)(['channel' => 'sms', 'address' => 'abc'], ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors.address.0', 'ফোন নম্বর লিখুন: ৬ থেকে ১৫টা অঙ্ক, চাইলে শুরুতে +।');
    foreach (['01711000000', '+8801711000000', '+880 1711-000000', '(02) 955.1234'] as $address) {
        ($this->send)(['channel' => 'sms', 'address' => $address])->assertCreated();
    }

    expect(($this->in)(fn () => NotificationMessage::query()->count()))->toBe(4);
});

it('carries a subject and the full text on any e-mail channel of the registry, the short text by phone', function () {
    Queue::fake();
    Sanctum::actingAs($this->owner);
    config(['notifications.channels.office_mail' => ['address' => 'email', 'class' => MailChannel::class]]);

    $message = ($this->send)(['channel' => 'office_mail', 'address' => 'qa@example.test'])->assertCreated()->json('data');

    expect($message['subject'])->toBe('Acme Ltd: পরীক্ষার বার্তা')
        ->and($message['body'])->toContain('উত্তর দেওয়ার দরকার নেই।');
});

it('notes the test message in the audit log, and a failed one goes again to the address typed', function () {
    Queue::fake();
    Sanctum::actingAs($this->owner);

    $id = ($this->send)(['channel' => 'mail', 'address' => 'typed@example.com'])->assertCreated()->json('data.id');

    $activity = Activity::query()->where('log_name', 'notifications')->where('event', 'test_sent')->sole();
    expect($activity->causer_id)->toBe($this->owner->id)
        ->and($activity->subject->public_id)->toBe($id)
        ->and($activity->properties->all())->toBe(['channel' => 'mail', 'address' => 'typed@example.com']);
    $this->getJson('/api/v1/audit/activities?filter[event]=test_sent')->assertOk()->assertJsonPath('data.0.changes.address', 'typed@example.com');

    ($this->stored)($id)->update(['status' => NotificationStatus::Failed]);
    $this->postJson("/api/v1/notifications/messages/{$id}/resend")->assertCreated()
        ->assertJsonPath('data.address', 'typed@example.com')->assertJsonPath('data.event.key', 'test');
});

it('limits how often', function () {
    Queue::fake();
    Sanctum::actingAs($this->owner);
    $limit = (int) config('notifications.manual.test_per_minute');
    for ($i = 0; $i < $limit; $i++) {
        ($this->send)(['channel' => 'mail', 'address' => 'qa@example.test'])->assertCreated();
    }
    ($this->send)(['channel' => 'mail', 'address' => 'qa@example.test'])->assertTooManyRequests();
});
