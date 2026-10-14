<?php

use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationMessage;
use App\Models\FamilyMember;
use App\Models\NotificationMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/*
| Sending a failed or skipped message again by hand (5.2d-3): POST /notifications/messages/{message}/resend,
| behind notifications.send. A new message with the same words goes to the person's address on record now; the
| old one stays as history, linked; once per message; noted in the audit log; whatever the settings (off here).
*/

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    $this->jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'email' => 'jane.new@example.com', 'personal_phone' => '+8801711000001']);
    $this->log = fn (array $attributes) => ($this->in)(fn () => NotificationMessage::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->jane->id, ...$attributes]));
    $this->failed = ($this->log)(['address' => 'jane.old@example.com', 'subject' => 'Card ready', 'body' => 'Your card is ready.', 'languages' => 'bn,en',
        'status' => NotificationStatus::Failed, 'error' => 'Mailbox full', 'attempts' => 4, 'failed_at' => now()]);
    $this->resend = fn (NotificationMessage $message) => $this->postJson("/api/v1/notifications/messages/{$message->public_id}/resend");
    $this->refusal = fn (NotificationMessage $message) => ($this->resend)($message)->assertUnprocessable()->json('errors.message.0');
});

it('lets the Owner and the Administrator send again, not the Viewer or the Operator', function () {
    Queue::fake();
    foreach (['Viewer' => 403, 'Operator' => 403] as $role => $status) {
        Sanctum::actingAs(tenantUser($this->tenant, $role));
        ($this->resend)($this->failed)->assertStatus($status);
    }
    Sanctum::actingAs(tenantUser($this->tenant, 'Administrator'));
    ($this->resend)($this->failed)->assertCreated();
});

it('sends the same words again as a new message, to the address on record now, keeping the old one', function () {
    Queue::fake();
    Sanctum::actingAs($this->owner);

    $new = ($this->resend)($this->failed)->assertCreated()->json('data');

    expect($new)->toMatchArray([
        'event' => ['key' => 'card_ready', 'name' => 'Card ready'],
        'address' => 'jane.new@example.com',
        'subject' => 'Card ready',
        'body' => 'Your card is ready.',
        'languages' => ['bn', 'en'],
        'status' => ['key' => 'queued', 'name' => 'Waiting'],
        'attempts' => 0,
        'error' => null,
        'sent_by' => ['id' => $this->owner->public_id, 'name' => $this->owner->name],
        'resend_of' => $this->failed->public_id,
        'resent_as' => null,
    ])->and($new['recipient']['id'])->toBe($this->jane->public_id);
    Queue::assertPushed(SendNotificationMessage::class, 1);

    // The old one as it was, now pointing to the new
    $this->getJson("/api/v1/notifications/messages/{$this->failed->public_id}")->assertOk()
        ->assertJsonPath('data.status.key', 'failed')
        ->assertJsonPath('data.address', 'jane.old@example.com')
        ->assertJsonPath('data.error', 'Mailbox full')
        ->assertJsonPath('data.resent_as', $new['id']);

    $activity = Activity::query()->where('log_name', 'notifications')->where('event', 'resent')->sole();
    expect($activity->causer_id)->toBe($this->owner->id)
        ->and($activity->subject->public_id)->toBe($new['id'])
        ->and($activity->properties->all())->toBe(['message' => $this->failed->public_id, 'event' => 'card_ready', 'channel' => 'mail', 'address' => 'jane.new@example.com']);

    // The audit page shows it, with its address
    $this->getJson('/api/v1/audit/activities?filter[event]=resent')->assertOk()->assertJsonPath('data.0.changes.address', 'jane.new@example.com');
});

it('really goes, though the organization has notifications off', function () {
    Sanctum::actingAs($this->owner);
    expect($this->tenant->notificationSettings()['enabled'])->toBeFalse();

    $new = ($this->resend)($this->failed)->assertCreated()->json('data.id');

    expect(($this->in)(fn () => NotificationMessage::query()->where('public_id', $new)->sole()->status))->toBe(NotificationStatus::Sent)
        ->and(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('sends again only a failed or skipped message, once — the next time the newer one', function () {
    Queue::fake();
    Sanctum::actingAs($this->owner);

    foreach ([NotificationStatus::Sent, NotificationStatus::Queued] as $status) {
        expect(($this->refusal)(($this->log)(['status' => $status])))->toBe('Only a failed message, or one that was not sent, can be sent again.');
    }

    $new = ($this->resend)($this->failed)->assertCreated()->json('data.id');
    expect(($this->refusal)($this->failed))->toBe('This message was already sent again; send the newer one again if needed.');

    // The newer one failed too: it can go again
    $newer = ($this->in)(fn () => NotificationMessage::query()->where('public_id', $new)->sole());
    $newer->update(['status' => NotificationStatus::Failed]);
    ($this->resend)($newer)->assertCreated()->assertJsonPath('data.resend_of', $new);

    expect(($this->in)(fn () => NotificationMessage::query()->count()))->toBe(5);
});

it('sends a message skipped for want of an address only once the record has one', function () {
    Queue::fake();
    Sanctum::actingAs($this->owner);
    $this->jane->update(['personal_phone' => null]);
    $skipped = ($this->log)(['channel' => 'sms', 'address' => null, 'subject' => null, 'status' => NotificationStatus::Skipped, 'reason' => 'no_address']);

    expect(($this->refusal)($skipped))->toBe('There is still no address on record for this channel. Add it to the person\'s record first.')
        ->and(($this->in)(fn () => NotificationMessage::query()->count()))->toBe(2);

    $this->jane->update(['personal_phone' => '+8801711999999']);
    ($this->resend)($skipped)->assertCreated()->assertJsonPath('data.address', '+8801711999999')->assertJsonPath('data.channel.key', 'sms');
});

it('sends a family member\'s message to their phone, and a test message to the address it was typed to', function () {
    Queue::fake();
    Sanctum::actingAs($this->owner);
    $son = ($this->in)(fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->jane->id, 'name' => 'Sam Doe', 'phone' => '+8801722000000']));
    $sms = ($this->log)(['cardholder_id' => null, 'family_member_id' => $son->id, 'channel' => 'sms', 'address' => '+8801700000000', 'status' => NotificationStatus::Failed]);
    $mail = ($this->log)(['cardholder_id' => null, 'family_member_id' => $son->id, 'channel' => 'mail', 'address' => null, 'status' => NotificationStatus::Skipped, 'reason' => 'no_address']);

    ($this->resend)($sms)->assertCreated()->assertJsonPath('data.address', '+8801722000000')->assertJsonPath('data.recipient.type', 'family_member');
    // A family member's record keeps no e-mail address
    expect(($this->refusal)($mail))->toContain('no address on record');

    $test = ($this->log)(['cardholder_id' => null, 'user_id' => $this->owner->id, 'sent_by' => $this->owner->id, 'event' => 'test', 'address' => 'typed@example.com', 'status' => NotificationStatus::Failed]);
    ($this->resend)($test)->assertCreated()->assertJsonPath('data.address', 'typed@example.com')->assertJsonPath('data.event.key', 'test');
});

it('refuses when the person or the channel is gone, in the reader\'s language', function () {
    Queue::fake();
    Sanctum::actingAs($this->owner);
    $gone = ($this->log)(['cardholder_id' => null, 'status' => NotificationStatus::Failed]);
    $whatsapp = ($this->log)(['channel' => 'whatsapp', 'status' => NotificationStatus::Failed]);

    expect(($this->refusal)($gone))->toBe('The person it went to is no longer on record.')
        ->and(($this->refusal)($whatsapp))->toBe('This channel is no longer available.');

    $this->postJson("/api/v1/notifications/messages/{$gone->public_id}/resend", [], ['Accept-Language' => 'bn'])
        ->assertUnprocessable()->assertJsonPath('errors.message.0', 'যাঁকে পাঠানো হয়েছিল, তাঁর রেকর্ড আর নেই।');
    expect(Activity::query()->where('event', 'resent')->count())->toBe(0);
});

it('never sends another organization\'s message, and limits how often', function () {
    Queue::fake();
    [$beta, $betaOwner] = tenantWithOwner('beta', 'owner@beta.test');
    Sanctum::actingAs($betaOwner);
    ($this->resend)($this->failed)->assertNotFound();

    Sanctum::actingAs($this->owner);
    $sent = ($this->log)(['status' => NotificationStatus::Sent]);
    $limit = (int) config('notifications.manual.resend_per_minute');
    for ($i = 0; $i < $limit; $i++) {
        ($this->resend)($sent)->assertUnprocessable();
    }
    ($this->resend)($sent)->assertTooManyRequests();
});
