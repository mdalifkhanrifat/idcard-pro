<?php

use App\Enums\UserKind;
use App\Enums\UserStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookFailure;
use App\Exceptions\WebhookNotDelivered;
use App\Jobs\SendNotificationMessage;
use App\Jobs\SendWebhook;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDeliveryService;
use App\Services\Webhooks\WebhookHostResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/*
| 6.2b: a delivery logged, queued, tried until it gets through or runs out of attempts, and an address that keeps
| failing turned off. No test here reaches the network.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    [$this->tenant, $this->owner] = tenantWithOwner('alpha', 'owner@example.com');
    $this->mock(WebhookHostResolver::class, fn ($mock) => $mock->shouldReceive('addresses')->andReturn(['93.184.216.34']));
    $this->service = app(WebhookDeliveryService::class);
    $this->inTenant = fn (Closure $work) => app(TenantContext::class)->run($this->tenant, $work);
    $this->hook = fn (array $attributes = []): Webhook => ($this->inTenant)(fn () => Webhook::factory()->create(['tenant_id' => $this->tenant->id, ...$attributes]));
    $this->queued = fn (Webhook $webhook, string $event = 'card.issued', array $data = ['number' => 'A-1']): WebhookDelivery => ($this->inTenant)(fn () => $this->service->queue($webhook, $event, $data));
    $this->answers = function (int $status): void {
        Http::fake(['*' => Http::response('', $status)]);
    };
});

it('logs a delivery in the fixed shape and hands it to its own queue, once saved', function () {
    $this->travelTo('2026-10-30 10:00:00');
    $delivery = ($this->queued)(($this->hook)());

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Queued)
        ->and($delivery->attempts)->toBe(0)
        ->and($delivery->payload)->toBe([
            'id' => $delivery->public_id,
            'event' => 'card.issued',
            'occurred_at' => '2026-10-30T10:00:00Z',
            'tenant' => 'alpha',
            'data' => ['number' => 'A-1'],
        ]);

    Queue::assertPushedOn('webhooks', SendWebhook::class, fn (SendWebhook $job) => $job->deliveryId === $delivery->id);
});

it('waits for what caused it to be saved, and is gone with it when that is undone', function () {
    $webhook = ($this->hook)();

    try {
        DB::transaction(function () use ($webhook) {
            ($this->queued)($webhook);

            throw new RuntimeException('changed my mind');
        });
    } catch (RuntimeException) {
    }

    Queue::assertPushed(SendWebhook::class, fn (SendWebhook $job) => $job->afterCommit === true);
    expect(WebhookDelivery::withoutGlobalScopes()->count())->toBe(0);
});

it('does nothing for a delivery that no longer exists', function () {
    Http::fake();

    $this->service->deliver(999);
    $this->service->fail(999);

    Http::assertNothingSent();
});

it('tries as often and as far apart as set, and is not sent twice at once', function () {
    config(['webhooks.retry.backoff' => [60, 300]]);
    $job = new SendWebhook(7);

    expect($job->tries())->toBe(3)
        ->and($job->backoff())->toBe([60, 300])
        ->and($job->uniqueId())->toBe('7')
        ->and($job->uniqueFor())->toBe(3960)
        ->and($job->timeout)->toBeGreaterThan(config('webhooks.connect_timeout') + config('webhooks.timeout'));
});

it('marks a delivery delivered, the status kept and nothing of the body', function () {
    $this->travelTo('2026-10-30 10:00:00');
    ($this->answers)(201);
    $delivery = ($this->queued)(($this->hook)());

    $this->service->deliver($delivery->id);

    $fresh = WebhookDelivery::withoutGlobalScopes()->find($delivery->id);
    expect($fresh->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($fresh->attempts)->toBe(1)
        ->and($fresh->response_code)->toBe(201)
        ->and($fresh->failure)->toBeNull()
        ->and($fresh->delivered_at->toDateTimeString())->toBe('2026-10-30 10:00:00');
    Http::assertSent(fn (Request $request) => $request->header('X-Webhook-Id') === [$delivery->public_id]);
});

it('never sends a delivered one again', function () {
    ($this->answers)(200);
    $delivery = ($this->queued)(($this->hook)());

    $this->service->deliver($delivery->id);
    $this->service->deliver($delivery->id);

    Http::assertSentCount(1);
});

it('keeps the same id across attempts, and clears the last failure on success', function () {
    $delivery = ($this->queued)(($this->hook)());
    $ids = [];
    $answers = [500, 503, 200];
    Http::fake(function (Request $request) use (&$ids, &$answers) {
        $ids[] = $request->header('X-Webhook-Id')[0];

        return Http::response('', array_shift($answers));
    });

    expect(fn () => $this->service->deliver($delivery->id))->toThrow(WebhookNotDelivered::class);
    $middle = WebhookDelivery::withoutGlobalScopes()->find($delivery->id);
    expect($middle->status)->toBe(WebhookDeliveryStatus::Queued)
        ->and($middle->failure)->toBe(WebhookFailure::Rejected)
        ->and($middle->response_code)->toBe(500)
        ->and($middle->attempts)->toBe(1);

    expect(fn () => $this->service->deliver($delivery->id))->toThrow(WebhookNotDelivered::class);
    $this->service->deliver($delivery->id);

    $fresh = WebhookDelivery::withoutGlobalScopes()->find($delivery->id);
    expect(array_unique($ids))->toBe([$delivery->public_id])
        ->and($fresh->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($fresh->failure)->toBeNull()
        ->and($fresh->response_code)->toBe(200)
        ->and($fresh->attempts)->toBe(3);
});

it('keeps an ordinary failure out of the application log', function () {
    $exception = new WebhookNotDelivered('abc', WebhookFailure::Timeout);

    expect($exception->getMessage())->toBe('Webhook delivery abc: timeout')
        ->and(method_exists($exception, 'report'))->toBeTrue();
});

it('sends nothing to a webhook turned off or deleted before its turn, without blaming the address', function () {
    Http::fake();
    $off = ($this->hook)();
    $deleted = ($this->hook)();
    $first = ($this->queued)($off);
    $second = ($this->queued)($deleted);
    ($this->inTenant)(function () use ($off, $deleted) {
        $off->update(['enabled' => false]);
        $deleted->delete();
    });

    $this->service->deliver($first->id);
    $this->service->deliver($second->id);

    Http::assertNothingSent();
    foreach ([$first, $second] as $delivery) {
        $fresh = WebhookDelivery::withoutGlobalScopes()->find($delivery->id);
        expect($fresh->status)->toBe(WebhookDeliveryStatus::Failed)->and($fresh->failure)->toBe(WebhookFailure::Disabled)->and($fresh->attempts)->toBe(0);
    }
    expect($off->fresh()->failure_count)->toBe(0);
});

it('counts a delivery once however many attempts it took, and a success starts again', function () {
    $status = 500;
    Http::fake(function () use (&$status) {
        return Http::response('', $status);
    });
    $webhook = ($this->hook)();
    $delivery = ($this->queued)($webhook);

    expect(fn () => $this->service->deliver($delivery->id))->toThrow(WebhookNotDelivered::class);
    expect(fn () => $this->service->deliver($delivery->id))->toThrow(WebhookNotDelivered::class);
    expect($webhook->fresh()->failure_count)->toBe(0);

    $this->service->fail($delivery->id);
    $this->service->fail($delivery->id);

    expect($webhook->fresh()->failure_count)->toBe(1)
        ->and(WebhookDelivery::withoutGlobalScopes()->find($delivery->id)->status)->toBe(WebhookDeliveryStatus::Failed);

    $status = 200;
    $this->service->deliver(($this->queued)($webhook)->id);

    expect($webhook->fresh()->failure_count)->toBe(0);
});

it('turns a webhook off once deliveries keep failing, tells those who look after it, and notes it', function () {
    config(['webhooks.disable_after' => 3]);
    $this->travelTo('2026-10-30 10:00:00');
    Queue::fake([SendWebhook::class, SendNotificationMessage::class]);
    $webhook = ($this->hook)(['name' => 'HR system']);
    tenantUser($this->tenant, 'Administrator', ['email' => 'admin@example.com']);
    tenantUser($this->tenant, 'Administrator', ['email' => 'left@example.com', 'status' => UserStatus::Inactive]);
    tenantUser($this->tenant, 'Viewer', ['email' => 'viewer@example.com']);
    tenantUser($this->tenant, 'Administrator', ['email' => 'account@example.com', 'kind' => UserKind::Integration]);

    foreach ([1, 2] as $failure) {
        $this->service->fail(($this->queued)($webhook)->id);
        expect($webhook->fresh()->enabled)->toBeTrue();
    }
    $this->service->fail(($this->queued)($webhook)->id);

    $fresh = $webhook->fresh();
    expect($fresh->enabled)->toBeFalse()
        ->and($fresh->failure_count)->toBe(3)
        ->and($fresh->disabled_at->toDateTimeString())->toBe('2026-10-30 10:00:00');

    $messages = ($this->inTenant)(fn () => NotificationMessage::query()->where('event', 'webhook_disabled')->get());
    expect($messages->pluck('address')->sort()->values()->all())->toBe(['admin@example.com', 'owner@example.com'])
        ->and($messages[0]->subject)->toContain('HR system')
        ->and($messages[0]->body)->toContain('৩')->not->toContain('3');

    // Further failures of what was already queued note nothing more
    $this->service->fail(($this->queued)($webhook)->id);
    expect(($this->inTenant)(fn () => NotificationMessage::query()->where('event', 'webhook_disabled')->count()))->toBe(2);

    Sanctum::actingAs($this->owner);
    $rows = collect($this->getJson('/api/v1/audit/activities?filter[log_name]=webhooks')->assertOk()->json('data'))->where('event', 'disabled')->values();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['fields'])->toContainEqual(['key' => 'name', 'label' => 'Name', 'old' => null, 'new' => 'HR system'])
        ->and(collect($rows[0]['fields'])->pluck('label')->all())->toContain('Deliveries failed in a row');
    expect(Activity::query()->where('log_name', 'webhooks')->where('event', 'disabled')->count())->toBe(1);
});

it('starts clean when turned on again, and only then', function () {
    Sanctum::actingAs($this->owner);
    $webhook = ($this->hook)(['enabled' => false, 'failure_count' => 20, 'disabled_at' => now()]);

    $this->patchJson("/api/v1/webhooks/{$webhook->public_id}", ['name' => 'Renamed'])->assertOk()
        ->assertJsonPath('data.failure_count', 20)->assertJsonPath('data.enabled', false);
    expect($webhook->fresh()->disabled_at)->not->toBeNull();

    $this->patchJson("/api/v1/webhooks/{$webhook->public_id}", ['enabled' => true])->assertOk()
        ->assertJsonPath('data.failure_count', 0)->assertJsonPath('data.disabled_at', null)->assertJsonPath('data.enabled', true);
});

it('writes a number in each language\'s digits in a message of several languages', function () {
    config(['webhooks.disable_after' => 12]);
    Queue::fake([SendWebhook::class, SendNotificationMessage::class]);
    ($this->inTenant)(fn () => $this->tenant->update(['settings' => ['notifications' => ['enabled' => false, 'channels' => ['mail'], 'languages' => ['bn', 'en']]]]));
    $webhook = ($this->hook)(['failure_count' => 11]);

    $this->service->fail(($this->queued)($webhook)->id);

    $body = ($this->inTenant)(fn () => NotificationMessage::query()->where('event', 'webhook_disabled')->sole()->body);
    expect($body)->toContain('১২')->toContain('after 12 deliveries');
});

it('tests an address at once, on or off, without counting a failure, and notes it', function () {
    Sanctum::actingAs($this->owner);
    $webhook = ($this->hook)(['enabled' => false]);
    ($this->answers)(204);

    $this->postJson("/api/v1/webhooks/{$webhook->public_id}/test")->assertCreated()
        ->assertJsonPath('data.event', ['key' => 'webhook.test', 'name' => 'Test'])
        ->assertJsonPath('data.status', ['key' => 'delivered', 'name' => 'Delivered'])
        ->assertJsonPath('data.response_code', 204)
        ->assertJsonPath('data.attempts', 1)
        ->assertJsonPath('data.failure', null);

    Http::assertSent(fn (Request $request) => $request->header('X-Webhook-Event') === ['webhook.test'] && json_decode($request->body(), true)['data'] === ['test' => true]);
    Queue::assertNothingPushed();

    $row = collect($this->getJson('/api/v1/audit/activities?filter[log_name]=webhooks')->json('data'))->firstWhere('event', 'tested');
    expect($row['causer']['name'])->toBe($this->owner->name);
});

it('says why a test failed, in the reader\'s language, and tries once', function () {
    Sanctum::actingAs($this->owner);
    $webhook = ($this->hook)();
    ($this->answers)(500);

    $this->withHeader('Accept-Language', 'bn')->postJson("/api/v1/webhooks/{$webhook->public_id}/test")->assertCreated()
        ->assertJsonPath('data.status.key', 'failed')
        ->assertJsonPath('data.response_code', 500)
        ->assertJsonPath('data.failure', ['key' => 'rejected', 'name' => __('webhooks.failures.rejected', locale: 'bn')]);

    Http::assertSentCount(1);
    expect($webhook->fresh()->failure_count)->toBe(0);
});

it('lets only those who may look after integrations test, and only their own organization\'s', function () {
    $webhook = ($this->hook)();
    [, $otherOwner] = tenantWithOwner('beta', 'owner@beta.example.com');
    Http::fake();

    Sanctum::actingAs($otherOwner);
    $this->postJson("/api/v1/webhooks/{$webhook->public_id}/test")->assertNotFound();

    $plain = ($this->inTenant)(fn () => User::factory()->create(['tenant_id' => $this->tenant->id]));
    app('auth')->forgetGuards();
    Sanctum::actingAs($plain);
    $this->postJson("/api/v1/webhooks/{$webhook->public_id}/test")->assertForbidden();

    Http::assertNothingSent();
});

it('limits tests per user and minute', function () {
    config(['webhooks.test_per_minute' => 5]);
    Sanctum::actingAs($this->owner);
    $webhook = ($this->hook)();
    ($this->answers)(200);

    foreach (range(1, 5) as $try) {
        $this->postJson("/api/v1/webhooks/{$webhook->public_id}/test")->assertCreated();
    }
    $this->postJson("/api/v1/webhooks/{$webhook->public_id}/test")->assertTooManyRequests();
});

it('takes back its own answer, the failure fields left as they were', function () {
    Sanctum::actingAs($this->owner);
    $webhook = ($this->hook)(['failure_count' => 2]);

    $answer = $this->getJson("/api/v1/webhooks/{$webhook->public_id}")->assertOk()->json('data');
    $this->putJson("/api/v1/webhooks/{$webhook->public_id}", $answer)->assertOk()->assertJsonPath('data.failure_count', 2);
});
