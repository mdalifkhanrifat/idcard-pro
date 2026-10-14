<?php

use App\Enums\NotificationStatus;
use App\Models\NotificationMessage;
use App\Repositories\Contracts\NotificationMessageRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'email' => 'jane@example.com']);
    $this->log = fn (array $attributes = []) => app(TenantContext::class)->run($this->tenant, fn () => app(NotificationMessageRepositoryInterface::class)->create([
        'event' => 'card_ready', 'channel' => 'mail', 'address' => 'jane@example.com', 'cardholder_id' => $this->jane->id,
        'languages' => 'bn,en', 'subject' => 'Ready', 'body' => 'Your card is ready.', ...$attributes,
    ]));
});

it('keeps each message with its own public id in its organization, queued until it goes out', function () {
    $message = ($this->log)();

    expect($message->public_id)->toHaveLength(26)
        ->and($message->tenant_id)->toBe($this->tenant->id)
        ->and($message->fresh()->status)->toBe(NotificationStatus::Queued)
        ->and($message->fresh()->attempts)->toBe(0)
        ->and($message->cardholder->full_name)->toBe('Jane Doe');

    // Another organization never sees it
    [$other] = tenantWithOwner('beta', 'owner@beta.test');
    expect(app(TenantContext::class)->run($other, fn () => NotificationMessage::query()->count()))->toBe(0)
        ->and(app(TenantContext::class)->run($this->tenant, fn () => NotificationMessage::query()->count()))->toBe(1);
});

it('keeps the message when the person it went to is deleted, only the link turns empty', function () {
    $message = ($this->log)();
    Sanctum::actingAs($this->owner);
    $this->deleteJson("/api/v1/cardholders/{$this->jane->public_id}")->assertNoContent();

    $kept = app(TenantContext::class)->run($this->tenant, fn () => NotificationMessage::query()->sole());
    expect($kept->id)->toBe($message->id)
        ->and($kept->cardholder_id)->toBeNull()
        ->and($kept->address)->toBe('jane@example.com')
        ->and($kept->body)->toBe('Your card is ready.');
});

it('is not written to the activity log, being a log itself', function () {
    ($this->log)(['status' => NotificationStatus::Sent, 'sent_at' => now()]);

    expect(Activity::query()->where('subject_type', (new NotificationMessage)->getMorphClass())->count())->toBe(0);
});
