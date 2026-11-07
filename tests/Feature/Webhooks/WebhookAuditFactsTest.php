<?php

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookFailure;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookHostResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| 6.2d: a webhook delivery sent again, and a test, record facts of their own rather than old and new values
| (config('audit.facts')). The audit page shows them as fields, named and written in the reader's language.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    [$this->tenant, $this->owner] = tenantWithOwner('alpha', 'owner@example.com');
    $this->mock(WebhookHostResolver::class, fn ($mock) => $mock->shouldReceive('addresses')->andReturn(['93.184.216.34']));
    $this->webhook = app(TenantContext::class)->run($this->tenant, fn () => Webhook::factory()->create(['tenant_id' => $this->tenant->id, 'events' => ['cardholder.updated']]));
    $this->rowOf = fn (string $event, string $locale = 'en') => collect($this->withHeader('Accept-Language', $locale)->getJson('/api/v1/audit/activities?filter[log_name]=webhooks')->assertOk()->json('data'))
        ->firstWhere('event', $event);
    Sanctum::actingAs($this->owner);
});

it('names what was sent again and from which delivery, in each language', function () {
    $old = app(TenantContext::class)->run($this->tenant, fn () => WebhookDelivery::factory()->create([
        'tenant_id' => $this->tenant->id, 'webhook_id' => $this->webhook->id, 'event' => 'cardholder.updated',
        'status' => WebhookDeliveryStatus::Failed, 'failure' => WebhookFailure::Timeout, 'attempts' => 6,
    ]));
    $new = $this->postJson("/api/v1/webhooks/{$this->webhook->public_id}/deliveries/{$old->public_id}/resend")->assertCreated()->json('data.id');

    expect(($this->rowOf)('resent')['fields'])->toBe([
        ['key' => 'delivery', 'label' => 'Delivery', 'old' => null, 'new' => $new],
        ['key' => 'resend_of', 'label' => 'Sent again from delivery', 'old' => null, 'new' => $old->public_id],
        ['key' => 'event', 'label' => 'Event', 'old' => null, 'new' => 'Cardholder changed'],
    ]);
    expect(collect(($this->rowOf)('resent', 'bn')['fields'])->pluck('new', 'label')->all())
        ->toMatchArray(['কোন ঘটনা' => 'কার্ডধারী বদল', 'যে ডেলিভারি আবার পাঠানো' => $old->public_id]);
});

it('names the result of a test, the answer code in the reader\'s digits', function () {
    Http::fake(['*' => Http::response('', 204)]);

    $this->postJson("/api/v1/webhooks/{$this->webhook->public_id}/test")->assertCreated();

    expect(collect(($this->rowOf)('tested')['fields'])->pluck('new', 'label')->except('Delivery')->all())->toBe(['Result' => 'Delivered', 'Answer code' => '204'])
        ->and(collect(($this->rowOf)('tested', 'bn')['fields'])->pluck('new', 'label')->only(['ফল', 'উত্তরের কোড'])->all())->toBe(['ফল' => 'পৌঁছেছে', 'উত্তরের কোড' => '২০৪']);
});

it('lists as facts only events that record no old and new values of their own', function () {
    foreach ((array) config('audit.facts') as $fact) {
        expect($fact)->toMatch('/^[a-z_]+\.[a-z_]+$/');
    }
    expect(config('audit.facts'))->not->toContain('webhooks.disabled');
});
