<?php

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookFailure;
use App\Jobs\SendWebhook;
use App\Models\Tenant;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDeliveryService;
use App\Services\Webhooks\WebhookHostResolver;
use App\Services\Webhooks\WebhookSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/*
| 6.2e-3: how often a delivery is tried and after how many failed deliveries in a row a webhook turns itself off are
| the organization's to choose, within the installation's schedule and limits. Fewer tries keep the first waits.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['webhooks.retry.backoff' => [60, 300, 1800, 7200, 21600], 'webhooks.disable_after' => 20, 'webhooks.disable_limits' => ['min' => 1, 'max' => 100]]);
    [$this->acme, $this->owner] = tenantWithOwner('acme', 'owner@acme.test');
    [$this->beta] = tenantWithOwner('beta', 'owner@beta.test');
    $this->mock(WebhookHostResolver::class, fn ($mock) => $mock->shouldReceive('addresses')->andReturn(['93.184.216.34']));
    $this->service = app(WebhookDeliveryService::class);
    $this->in = fn (Tenant $tenant, Closure $work) => app(TenantContext::class)->run($tenant, $work);
    $this->hook = fn (Tenant $tenant): Webhook => ($this->in)($tenant, fn () => Webhook::factory()->create(['tenant_id' => $tenant->id]));
    $this->queued = fn (Tenant $tenant, Webhook $webhook): WebhookDelivery => ($this->in)($tenant, fn () => $this->service->queue($webhook, 'card.issued', ['number' => 'A-1']));
    $this->choose = fn (Tenant $tenant, array $webhooks) => $tenant->forceFill(['settings' => [...($tenant->settings ?? []), 'webhooks' => $webhooks]])->save();
    $this->save = fn (array $webhooks) => $this->postJson('/api/v1/settings', ['name' => 'Acme', 'timezone' => 'Asia/Dhaka', 'locale' => 'en', 'primary_color' => '#112233', 'webhooks' => $webhooks]);
});

it('tries six times and turns off after twenty by default, and says what each can be set to', function () {
    Sanctum::actingAs($this->owner);

    $answer = $this->getJson('/api/v1/settings')->assertOk();
    expect($answer->json('data.webhooks'))->toBe(['prune_log' => false, 'log_days' => 365, 'attempts' => 6, 'disable_after' => 20])
        ->and($answer->json('data.webhook_options'))->toBe([
            'log_days' => ['min' => 7, 'max' => 3650],
            'attempts' => ['min' => 1, 'max' => 6, 'backoff' => [60, 300, 1800, 7200, 21600]],
            'disable_after' => ['min' => 1, 'max' => 100],
        ]);
});

it('is set through the organization settings within the limits, unsent parts left alone, and noted by name', function () {
    Sanctum::actingAs($this->owner);

    ($this->save)(['attempts' => 2])->assertOk()->assertJsonPath('data.webhooks.attempts', 2)->assertJsonPath('data.webhooks.disable_after', 20);
    ($this->save)(['disable_after' => 5])->assertOk()->assertJsonPath('data.webhooks.attempts', 2)->assertJsonPath('data.webhooks.disable_after', 5);

    foreach ([['attempts' => 0], ['attempts' => 7], ['disable_after' => 0], ['disable_after' => 101], ['attempts' => 'many']] as $wrong) {
        ($this->save)($wrong)->assertUnprocessable()->assertJsonValidationErrors('webhooks.'.array_key_first($wrong));
    }
    ($this->save)($this->getJson('/api/v1/settings')->json('data.webhooks'))->assertOk()
        ->assertJsonPath('data.webhooks', ['prune_log' => false, 'log_days' => 365, 'attempts' => 2, 'disable_after' => 5]);

    $changes = Activity::query()->where('subject_type', $this->acme->getMorphClass())->where('subject_id', $this->acme->id)->get()
        ->flatMap(fn (Activity $activity) => array_keys((array) ($activity->attribute_changes['attributes'] ?? [])));
    expect($changes)->toContain('settings.webhooks.attempts', 'settings.webhooks.disable_after');

    $fields = collect($this->getJson('/api/v1/audit/activities?filter[log_name]=tenant')->assertOk()->json('data'))->flatMap(fn (array $row) => $row['fields']);
    expect($fields->firstWhere('key', 'settings.webhooks.attempts')['label'])->toBe('Tries per delivery');
});

it('names the fields in the reader\'s language when a value is out of range', function () {
    Sanctum::actingAs($this->owner);

    $this->withHeader('Accept-Language', 'bn');
    $message = ($this->save)(['attempts' => 9])->assertUnprocessable()->json('errors')['webhooks.attempts'][0];

    expect($message)->toContain('ডেলিভারি-প্রতি চেষ্টা');
});

it('keeps saved values within what the installation allows now', function () {
    expect(WebhookSettings::normalize(['attempts' => 9, 'disable_after' => 500]))->toMatchArray(['attempts' => 6, 'disable_after' => 100])
        ->and(WebhookSettings::normalize(['attempts' => 0, 'disable_after' => 0]))->toMatchArray(['attempts' => 1, 'disable_after' => 1]);

    config(['webhooks.retry.backoff' => [60, 300]]);
    expect(WebhookSettings::normalize(['attempts' => 6])['attempts'])->toBe(3);
});

it('keeps the first waits for fewer tries, never a schedule of its own', function () {
    expect(WebhookSettings::backoff(6))->toBe([60, 300, 1800, 7200, 21600])
        ->and(WebhookSettings::backoff(3))->toBe([60, 300])
        ->and(WebhookSettings::backoff(1))->toBe([]);
});

it('queues each organization\'s deliveries with its own tries', function () {
    ($this->choose)($this->acme, ['attempts' => 3]);
    ($this->queued)($this->acme, ($this->hook)($this->acme));
    ($this->queued)($this->beta, ($this->hook)($this->beta));

    $jobs = Queue::pushed(SendWebhook::class);
    expect($jobs)->toHaveCount(2)
        ->and($jobs[0]->tries())->toBe(3)
        ->and($jobs[0]->backoff())->toBe([60, 300])
        ->and($jobs[0]->uniqueFor())->toBe(360 + 3600)
        ->and($jobs[1]->tries())->toBe(6)
        ->and($jobs[1]->backoff())->toBe([60, 300, 1800, 7200, 21600]);
});

it('tries once only, with no wait, when the organization chose one try', function () {
    ($this->choose)($this->acme, ['attempts' => 1]);
    ($this->queued)($this->acme, ($this->hook)($this->acme));

    $job = Queue::pushed(SendWebhook::class)->first();
    expect($job->tries())->toBe(1)
        ->and($job->backoff())->toBe([])
        ->and($job->uniqueFor())->toBe(3600);
});

it('sends a failed delivery again with the organization\'s tries of now', function () {
    ($this->choose)($this->acme, ['attempts' => 2]);
    $webhook = ($this->hook)($this->acme);
    $old = ($this->in)($this->acme, fn () => WebhookDelivery::factory()->create([
        'tenant_id' => $this->acme->id, 'webhook_id' => $webhook->id, 'event' => 'card.issued',
        'status' => WebhookDeliveryStatus::Failed, 'failure' => WebhookFailure::Timeout, 'attempts' => 6,
    ]));

    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/webhooks/{$webhook->public_id}/deliveries/{$old->public_id}/resend")->assertCreated();

    $job = Queue::pushed(SendWebhook::class)->first();
    expect($job->tries())->toBe(2)->and($job->backoff())->toBe([60]);
});

it('still runs a job queued before organizations chose, on the whole schedule', function () {
    $job = unserialize(str_replace('s:8:"schedule";a:1:{i:0;i:60;}', 's:8:"schedule";N;', serialize(new SendWebhook(7, [60]))));

    expect($job->tries())->toBe(6)
        ->and($job->backoff())->toBe([60, 300, 1800, 7200, 21600]);

    $old = 'O:20:"App\Jobs\SendWebhook":2:{s:10:"deliveryId";i:7;s:7:"timeout";i:60;}';
    expect(unserialize($old)->tries())->toBe(6);
});

it('turns each organization\'s webhook off after its own count', function () {
    ($this->choose)($this->acme, ['disable_after' => 2]);
    $acme = ($this->hook)($this->acme);
    $beta = ($this->hook)($this->beta);

    foreach ([1, 2] as $failure) {
        $this->service->fail(($this->queued)($this->acme, $acme)->id);
        $this->service->fail(($this->queued)($this->beta, $beta)->id);
    }

    expect($acme->fresh()->enabled)->toBeFalse()
        ->and($acme->fresh()->failure_count)->toBe(2)
        ->and($beta->fresh()->enabled)->toBeTrue()
        ->and($beta->fresh()->failure_count)->toBe(2);
});

it('turns off at the next failure a webhook already past a count made lower', function () {
    $webhook = ($this->hook)($this->acme);
    $webhook->forceFill(['failure_count' => 8])->save();
    ($this->choose)($this->acme, ['disable_after' => 5]);

    $this->service->fail(($this->queued)($this->acme, $webhook)->id);

    expect($webhook->fresh()->enabled)->toBeFalse()->and($webhook->fresh()->failure_count)->toBe(9);
});

it('tells the webhook list after how many failures one turns itself off', function () {
    ($this->choose)($this->acme, ['disable_after' => 7]);
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/webhooks')->assertOk()->assertJsonPath('meta.disable_after', 7);
});
