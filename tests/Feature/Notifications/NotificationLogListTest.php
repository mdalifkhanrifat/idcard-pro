<?php

use App\Enums\NotificationStatus;
use App\Models\Card;
use App\Models\FamilyMember;
use App\Models\NotificationMessage;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
| The log of sent messages (5.2d-2): GET /notifications/messages and /{message}, behind notifications.view, with
| the full address and text (the owner's decision), filters the report will share, and names of every key.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $in = fn (callable $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    $this->jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => 'E-100']);
    $this->john = cardholder($this->tenant, ['full_name' => 'John Roe']);
    $this->son = $in(fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->jane->id, 'name' => 'Sam Doe']));
    $this->card = $in(fn () => Card::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->jane->id, 'number' => 'EMP/26000042']));
    $this->log = fn (array $attributes) => $in(fn () => NotificationMessage::factory()->create(['tenant_id' => $this->tenant->id, ...$attributes]));

    // Logged around the organization's midnight (Dhaka, UTC+6) between 5 and 6 April
    $this->ready = ($this->log)(['cardholder_id' => $this->jane->id, 'card_id' => $this->card->id, 'address' => 'jane@example.com', 'subject' => 'Card ready',
        'languages' => 'bn,en', 'status' => NotificationStatus::Sent, 'attempts' => 1, 'driver' => 'smtp', 'sent_at' => CarbonImmutable::parse('2026-04-05 23:40', 'Asia/Dhaka')->utc(),
        'created_at' => CarbonImmutable::parse('2026-04-05 23:30', 'Asia/Dhaka')->utc()]);
    $this->sms = ($this->log)(['channel' => 'sms', 'family_member_id' => $this->son->id, 'address' => '+8801711000000', 'subject' => null, 'status' => NotificationStatus::Failed,
        'error' => 'Gateway down', 'attempts' => 4, 'failed_at' => now(), 'created_at' => CarbonImmutable::parse('2026-04-06 00:30', 'Asia/Dhaka')->utc()]);
    $this->skipped = ($this->log)(['cardholder_id' => $this->john->id, 'address' => null, 'subject' => 'Expiring', 'event' => 'card_expiring', 'status' => NotificationStatus::Skipped,
        'reason' => 'no_address', 'created_at' => CarbonImmutable::parse('2026-04-07 10:00', 'Asia/Dhaka')->utc()]);
    $this->test = ($this->log)(['event' => 'test', 'user_id' => $this->owner->id, 'sent_by' => $this->owner->id, 'address' => 'owner@example.com', 'subject' => 'Test',
        'status' => NotificationStatus::Queued, 'created_at' => CarbonImmutable::parse('2026-04-08 10:00', 'Asia/Dhaka')->utc()]);

    $this->ids = fn (array $query = []) => array_column($this->getJson('/api/v1/notifications/messages?'.http_build_query($query))->assertOk()->json('data'), 'id');
});

it('lets the Owner, the Administrator and the Viewer read the log, not the Operator', function () {
    foreach (['Administrator' => 200, 'Viewer' => 200, 'Operator' => 403] as $role => $status) {
        Sanctum::actingAs(tenantUser($this->tenant, $role));
        $this->getJson('/api/v1/notifications/messages')->assertStatus($status);
        $this->getJson("/api/v1/notifications/messages/{$this->ready->public_id}")->assertStatus($status);
    }
    Sanctum::actingAs($this->owner);
    $this->getJson('/api/v1/notifications/messages')->assertOk()->assertJsonCount(4, 'data');
});

it('shows each message whole: full address and text, whom and what about, every key with its name', function () {
    Sanctum::actingAs($this->owner);

    $rows = collect($this->getJson('/api/v1/notifications/messages')->assertOk()->json('data'))->keyBy('id');

    expect($rows[$this->ready->public_id])->toMatchArray([
        'event' => ['key' => 'card_ready', 'name' => 'Card ready'],
        'channel' => ['key' => 'mail', 'name' => 'E-mail'],
        'address' => 'jane@example.com',
        'recipient' => ['type' => 'cardholder', 'id' => $this->jane->public_id, 'name' => 'Jane Doe'],
        'card' => ['id' => $this->card->public_id, 'number' => 'EMP/26000042'],
        'languages' => ['bn', 'en'],
        'subject' => 'Card ready',
        'body' => $this->ready->body,
        'status' => ['key' => 'sent', 'name' => 'Sent'],
        'reason' => null,
        'attempts' => 1,
        'driver' => 'smtp',
        'sent_by' => null,
    ])
        // A family member's message links to the cardholder, whose page holds theirs
        ->and($rows[$this->sms->public_id]['recipient'])->toBe(['type' => 'family_member', 'id' => $this->son->public_id, 'name' => 'Sam Doe', 'cardholder_id' => $this->jane->public_id])
        ->and($rows[$this->sms->public_id]['error'])->toBe('Gateway down')
        ->and($rows[$this->skipped->public_id]['reason'])->toBe(['key' => 'no_address', 'name' => 'No address on record'])
        ->and($rows[$this->test->public_id]['event'])->toBe(['key' => 'test', 'name' => 'Test message'])
        ->and($rows[$this->test->public_id]['recipient']['type'])->toBe('user')
        ->and($rows[$this->test->public_id]['sent_by'])->toBe(['id' => $this->owner->public_id, 'name' => $this->owner->name]);

    // One message alone, the same
    $this->getJson("/api/v1/notifications/messages/{$this->sms->public_id}")->assertOk()
        ->assertJsonPath('data.address', '+8801711000000')
        ->assertJsonPath('data.status.name', 'Failed');

    // In Bangla
    $this->getJson("/api/v1/notifications/messages/{$this->skipped->public_id}", ['Accept-Language' => 'bn'])->assertOk()
        ->assertJsonPath('data.event.name', 'কার্ডের মেয়াদ শেষ হচ্ছে')
        ->assertJsonPath('data.status.name', 'পাঠানো হয়নি')
        ->assertJsonPath('data.reason.name', 'ঠিকানা সংরক্ষিত নেই');
});

it('lists what to filter by from the registry, with what the log holds since removed from it', function () {
    Sanctum::actingAs($this->owner);
    // A channel and an event no longer in the registry, still in the log
    ($this->log)(['channel' => 'whatsapp', 'event' => 'old_event']);

    $meta = $this->getJson('/api/v1/notifications/messages', ['Accept-Language' => 'bn'])->assertOk()->json('meta');

    expect($meta['events'])->toBe([
        ['key' => 'card_ready', 'name' => 'কার্ড তৈরি'],
        ['key' => 'card_expiring', 'name' => 'কার্ডের মেয়াদ শেষ হচ্ছে'],
        ['key' => 'appointment_booked', 'name' => 'অ্যাপয়েন্টমেন্ট বুক হয়েছে'],
        ['key' => 'appointment_changed', 'name' => 'অ্যাপয়েন্টমেন্ট সরানো হয়েছে'],
        ['key' => 'appointment_cancelled', 'name' => 'অ্যাপয়েন্টমেন্ট বাতিল'],
        ['key' => 'appointment_reminder', 'name' => 'অ্যাপয়েন্টমেন্টের রিমাইন্ডার'],
        ['key' => 'test', 'name' => 'পরীক্ষার বার্তা'],
        ['key' => 'old_event', 'name' => 'old_event'],
    ])
        ->and($meta['channels'])->toBe([['key' => 'mail', 'name' => 'ইমেইল'], ['key' => 'sms', 'name' => 'SMS'], ['key' => 'whatsapp', 'name' => 'whatsapp']])
        ->and(array_column($meta['statuses'], 'key'))->toBe(['queued', 'sent', 'failed', 'skipped'])
        ->and($meta['statuses'][1]['name'])->toBe('পাঠানো হয়েছে');

    // A new event of the registry appears by itself
    config(['notifications.events.appointment_booked' => config('notifications.events.card_ready')]);
    expect(array_column($this->getJson('/api/v1/notifications/messages')->json('meta.events'), 'key'))->toContain('appointment_booked');
});

it('filters by status, channel, event, search, cardholder (with their family) and card, and sorts', function () {
    Sanctum::actingAs($this->owner);
    [$ready, $sms, $skipped, $test] = [$this->ready->public_id, $this->sms->public_id, $this->skipped->public_id, $this->test->public_id];

    expect(($this->ids)())->toBe([$test, $skipped, $sms, $ready])
        ->and(($this->ids)(['filter' => ['status' => 'failed']]))->toBe([$sms])
        ->and(($this->ids)(['filter' => ['channel' => 'sms']]))->toBe([$sms])
        ->and(($this->ids)(['filter' => ['event' => 'card_expiring']]))->toBe([$skipped])
        ->and(($this->ids)(['filter' => ['event' => 'test']]))->toBe([$test])
        ->and(($this->ids)(['filter' => ['search' => 'jane@']]))->toBe([$ready])
        ->and(($this->ids)(['filter' => ['search' => 'Expiring']]))->toBe([$skipped])
        ->and(($this->ids)(['filter' => ['search' => 'E-100']]))->toBe([$ready])
        ->and(($this->ids)(['filter' => ['search' => 'Sam']]))->toBe([$sms])
        ->and(($this->ids)(['filter' => ['search' => '26000042']]))->toBe([$ready])
        ->and(($this->ids)(['filter' => ['search' => $this->owner->name]]))->toBe([$test])
        ->and(($this->ids)(['filter' => ['cardholder' => $this->jane->public_id]]))->toBe([$sms, $ready])
        ->and(($this->ids)(['filter' => ['cardholder' => $this->john->public_id]]))->toBe([$skipped])
        ->and(($this->ids)(['filter' => ['card' => $this->card->public_id]]))->toBe([$ready])
        ->and(($this->ids)(['sort' => 'created_at']))->toBe([$ready, $sms, $skipped, $test])
        // Not yet sent last, newest sending first
        ->and(($this->ids)(['sort' => '-sent_at'])[0])->toBe($ready);

    $page = $this->getJson('/api/v1/notifications/messages?per_page=10')->assertOk();
    expect($page->json('meta.per_page'))->toBe(10);
});

it('takes dates as the organization\'s days, the last one whole', function () {
    Sanctum::actingAs($this->owner);

    // 23:30 on 5 April in Dhaka is 17:30 UTC; 00:30 on 6 April is 18:30 UTC on the 5th
    expect(($this->ids)(['filter' => ['date_from' => '2026-04-05', 'date_to' => '2026-04-05']]))->toBe([$this->ready->public_id])
        ->and(($this->ids)(['filter' => ['date_from' => '2026-04-06', 'date_to' => '2026-04-07']]))->toBe([$this->skipped->public_id, $this->sms->public_id])
        ->and(($this->ids)(['filter' => ['date_from' => '2026-04-08']]))->toBe([$this->test->public_id]);

    $this->getJson('/api/v1/notifications/messages?filter[date_from]=2026-04-07&filter[date_to]=2026-04-06', ['Accept-Language' => 'bn'])
        ->assertUnprocessable()->assertJsonValidationErrors(['filter.date_to']);
});

it('keeps each organization\'s log to itself', function () {
    [$beta, $betaOwner] = tenantWithOwner('beta', 'owner@beta.test');
    $theirs = app(TenantContext::class)->run($beta, fn () => NotificationMessage::factory()->create(['tenant_id' => $beta->id]));
    Sanctum::actingAs($this->owner);

    expect(($this->ids)())->not->toContain($theirs->public_id);
    $this->getJson("/api/v1/notifications/messages/{$theirs->public_id}")->assertNotFound();

    // Their cardholder's id finds nothing here
    $theirHolder = cardholder($beta);
    expect(($this->ids)(['filter' => ['cardholder' => $theirHolder->public_id]]))->toBe([]);

    Sanctum::actingAs($betaOwner);
    expect(($this->ids)())->toBe([$theirs->public_id]);
});

it('keeps a message whose person was deleted, with its address and no one to link to', function () {
    Sanctum::actingAs($this->owner);
    $this->deleteJson("/api/v1/cardholders/{$this->john->public_id}")->assertNoContent();

    $this->getJson("/api/v1/notifications/messages/{$this->skipped->public_id}")->assertOk()
        ->assertJsonPath('data.recipient', null)
        ->assertJsonPath('data.subject', 'Expiring');
});

it('refuses filters it does not know, in the reader\'s language', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/notifications/messages?filter[status]=lost')->assertUnprocessable()->assertJsonValidationErrors(['filter.status']);
    $this->getJson('/api/v1/notifications/messages?filter[unknown]=1')->assertUnprocessable()->assertJsonValidationErrors(['filter']);
    $this->getJson('/api/v1/notifications/messages?sort=address')->assertUnprocessable()->assertJsonValidationErrors(['sort']);
    $this->getJson('/api/v1/notifications/messages?filter[channel]=Mail!')->assertUnprocessable()->assertJsonValidationErrors(['filter.channel']);
    $this->getJson('/api/v1/notifications/messages?per_page=2001')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);

    expect($this->getJson('/api/v1/notifications/messages?filter[card]=short', ['Accept-Language' => 'bn'])->assertUnprocessable()->json('errors')['filter.card'][0])
        ->toContain('কার্ড');
});
