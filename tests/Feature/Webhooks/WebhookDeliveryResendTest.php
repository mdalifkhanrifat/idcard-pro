<?php

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookFailure;
use App\Jobs\SendWebhook;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDeliveryService;
use App\Services\Webhooks\WebhookHostResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/*
| 6.2b-4: a failed delivery sent again by hand. A new line in the log, the exact text and so the same id for the
| receiver, signed afresh; once per delivery; never past what the person may see. The answers say what a resend would.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    [$this->tenant, $this->owner] = tenantWithOwner('alpha', 'owner@example.com');
    $this->mock(WebhookHostResolver::class, fn ($mock) => $mock->shouldReceive('addresses')->andReturn(['93.184.216.34']));
    $this->inTenant = fn (Closure $work) => app(TenantContext::class)->run($this->tenant, $work);
    $this->webhook = ($this->inTenant)(fn () => Webhook::factory()->create(['tenant_id' => $this->tenant->id, 'events' => ['cardholder.created', 'card.issued']]));
    $this->failed = fn (array $attributes = []): WebhookDelivery => ($this->inTenant)(fn () => WebhookDelivery::factory()->create([
        'tenant_id' => $this->tenant->id,
        'webhook_id' => $this->webhook->id,
        'status' => WebhookDeliveryStatus::Failed,
        'failure' => WebhookFailure::Timeout,
        'attempts' => 6,
        ...$attributes,
    ]));
    $this->resend = fn (WebhookDelivery $delivery) => $this->postJson("/api/v1/webhooks/{$this->webhook->public_id}/deliveries/{$delivery->public_id}/resend");
    $this->detail = fn (WebhookDelivery $delivery) => $this->getJson("/api/v1/webhooks/{$this->webhook->public_id}/deliveries/{$delivery->public_id}");
});

it('sends the exact text again as a new line, the old one kept and linked', function () {
    $text = '{"id":"01KORIGINAL","event":"card.issued","data":{"custom_fields":{}}}';
    $old = ($this->failed)(['event' => 'card.issued', 'payload' => $text]);

    Sanctum::actingAs($this->owner);
    $answer = ($this->resend)($old)->assertCreated()
        ->assertJsonPath('data.status.key', 'queued')
        ->assertJsonPath('data.resend_of', $old->public_id)
        ->assertJsonPath('data.payload', $text);

    $new = WebhookDelivery::query()->withoutGlobalScopes()->where('public_id', $answer->json('data.id'))->firstOrFail();

    expect($new->id)->not->toBe($old->id)
        ->and($new->payload)->toBe($text)
        ->and($new->event)->toBe('card.issued')
        ->and($new->attempts)->toBe(0)
        ->and($old->fresh()->status)->toBe(WebhookDeliveryStatus::Failed);

    ($this->detail)($old)->assertOk()->assertJsonPath('data.resent_as', $new->public_id);
    Queue::assertPushedOn('webhooks', SendWebhook::class, fn (SendWebhook $job) => $job->deliveryId === $new->id && $job->afterCommit === true);

    $note = Activity::query()->where('log_name', 'webhooks')->where('event', 'resent')->sole();
    expect($note->causer_id)->toBe($this->owner->id)
        ->and($note->subject_id)->toBe($this->webhook->id)
        ->and($note->properties['delivery'])->toBe($new->public_id)
        ->and($note->properties['resend_of'])->toBe($old->public_id);
});

it('reaches the receiver under the id it already knows, signed afresh', function () {
    $this->travelTo('2026-10-30 10:00:00');
    $original = ($this->inTenant)(fn () => app(WebhookDeliveryService::class)->queue($this->webhook, 'card.issued', ['number' => 'A-1']));
    $original->update(['status' => WebhookDeliveryStatus::Failed]);

    $this->travelTo('2026-10-30 12:00:00');
    Sanctum::actingAs($this->owner);
    $new = ($this->resend)($original)->assertCreated()->json('data.id');
    Http::fake(['*' => Http::response('', 200)]);

    app(WebhookDeliveryService::class)->deliver(WebhookDelivery::query()->withoutGlobalScopes()->where('public_id', $new)->value('id'));

    Http::assertSent(function (Request $request) use ($original): bool {
        $stamp = $request->header('X-Webhook-Timestamp')[0];
        $signature = 'sha256='.hash_hmac('sha256', "{$stamp}.{$original->payload}", $this->webhook->secret);

        return $request->body() === $original->payload
            && $request->header('X-Webhook-Id')[0] === $original->public_id
            && $stamp === (string) now()->getTimestamp()
            && $request->header('X-Webhook-Signature')[0] === $signature;
    });
    expect(WebhookDelivery::query()->withoutGlobalScopes()->where('public_id', $new)->value('status'))->toBe(WebhookDeliveryStatus::Delivered);
});

it('goes once; if that one fails too, that one is what goes next', function () {
    $old = ($this->failed)();
    Sanctum::actingAs($this->owner);

    $first = ($this->resend)($old)->assertCreated()->json('data.id');
    ($this->resend)($old)->assertUnprocessable()->assertJsonValidationErrors('delivery');

    $next = WebhookDelivery::query()->withoutGlobalScopes()->where('public_id', $first)->firstOrFail();
    $next->update(['status' => WebhookDeliveryStatus::Failed]);

    ($this->resend)($next)->assertCreated();
    expect(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(3);
});

it('says in its answers exactly what a resend would do', function (Closure $arrange, ?string $reason) {
    [$delivery, $actor] = $arrange($this);
    Sanctum::actingAs($actor);

    $offered = ($this->detail)($delivery)->assertOk()->json('data.actions.resend');
    $listed = collect($this->getJson("/api/v1/webhooks/{$this->webhook->public_id}/deliveries")->assertOk()->json('data'))
        ->firstWhere('id', $delivery->public_id)['actions']['resend'];
    $answer = ($this->resend)($delivery);

    expect($listed)->toBe($offered);

    if ($reason === null) {
        expect($offered)->toBe(['allowed' => true, 'reason' => null]);
        $answer->assertCreated();

        return;
    }

    $answer->assertUnprocessable();
    expect($answer->json('errors.delivery.0'))->toBe(__("webhooks.resend.{$reason}"));
    if (in_array($reason, ['test', 'not_failed'], true)) {
        expect($offered)->toBeNull();
    } else {
        expect($offered)->toBe(['allowed' => false, 'reason' => __("webhooks.resend.{$reason}")]);
    }
})->with([
    'failed' => [fn ($test) => [($test->failed)(), $test->owner], null],
    'delivered' => [fn ($test) => [($test->failed)(['status' => WebhookDeliveryStatus::Delivered, 'failure' => null]), $test->owner], 'not_failed'],
    'still waiting' => [fn ($test) => [($test->failed)(['status' => WebhookDeliveryStatus::Queued]), $test->owner], 'not_failed'],
    'a test' => [fn ($test) => [($test->failed)(['event' => WebhookDeliveryService::TEST_EVENT]), $test->owner], 'test'],
    'sent again already' => [function ($test) {
        $old = ($test->failed)();
        ($test->inTenant)(fn () => WebhookDelivery::factory()->create(['tenant_id' => $test->tenant->id, 'webhook_id' => $test->webhook->id, 'resend_of_id' => $old->id]));

        return [$old, $test->owner];
    }, 'already'],
    'webhook off' => [function ($test) {
        ($test->inTenant)(fn () => $test->webhook->update(['enabled' => false]));

        return [($test->failed)(), $test->owner];
    }, 'disabled'],
    'data the person may not see' => [function ($test) {
        $user = tenantUser($test->tenant, 'Viewer');
        ($test->inTenant)(fn () => $user->roles()->firstOrFail()->syncPermissions(['integrations.manage', 'cards.view']));

        return [($test->failed)(['event' => 'cardholder.created']), $user];
    }, 'beyond'],
]);

it('answers a test with the same shape, nothing to send again', function () {
    Http::fake(['*' => Http::response('', 500)]);
    Sanctum::actingAs($this->owner);

    $this->postJson("/api/v1/webhooks/{$this->webhook->public_id}/test")->assertCreated()
        ->assertJsonPath('data.status.key', 'failed')
        ->assertJsonPath('data.actions.resend', null);
});

it('works under its own webhook and organization, with the permission only', function () {
    $delivery = ($this->failed)();
    $other = ($this->inTenant)(fn () => Webhook::factory()->create(['tenant_id' => $this->tenant->id]));
    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/webhooks/{$other->public_id}/deliveries/{$delivery->public_id}/resend")->assertNotFound();

    [, $otherOwner] = tenantWithOwner('beta', 'owner@beta.example.com');
    app('auth')->forgetGuards();
    Sanctum::actingAs($otherOwner);
    ($this->resend)($delivery)->assertNotFound();

    $plain = ($this->inTenant)(fn () => User::factory()->create(['tenant_id' => $this->tenant->id]));
    app('auth')->forgetGuards();
    Sanctum::actingAs($plain);
    ($this->resend)($delivery)->assertForbidden();

    expect(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('limits how often per user and minute', function () {
    Sanctum::actingAs($this->owner);
    $limit = (int) config('webhooks.resend_per_minute');

    foreach (range(1, $limit) as $try) {
        ($this->resend)(($this->failed)())->assertCreated();
    }
    ($this->resend)(($this->failed)())->assertTooManyRequests();
});
