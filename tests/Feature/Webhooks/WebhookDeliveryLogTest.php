<?php

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookFailure;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

/*
| 6.2b-4: the log of a webhook's deliveries. What was sent holds personal data: it is in the answer about one delivery
| only, and only for someone who may see that data themselves (else a webhook would be a way round the permission).
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner('alpha', 'owner@example.com');
    $this->inTenant = fn (Closure $work) => app(TenantContext::class)->run($this->tenant, $work);
    $this->webhook = ($this->inTenant)(fn () => Webhook::factory()->create(['tenant_id' => $this->tenant->id]));
    $this->delivery = fn (array $attributes = [], ?Webhook $webhook = null): WebhookDelivery => ($this->inTenant)(fn () => WebhookDelivery::factory()->create([
        'tenant_id' => $this->tenant->id,
        'webhook_id' => ($webhook ?? $this->webhook)->id,
        ...$attributes,
    ]));
    $this->url = fn (?string $rest = null): string => "/api/v1/webhooks/{$this->webhook->public_id}/deliveries".($rest === null ? '' : "/{$rest}");
});

it('lists the deliveries of this webhook, newest first, without what was sent', function () {
    $older = ($this->delivery)(['created_at' => now()->subHour()]);
    $newer = ($this->delivery)(['status' => WebhookDeliveryStatus::Failed, 'failure' => WebhookFailure::Timeout, 'attempts' => 6]);
    $other = ($this->inTenant)(fn () => Webhook::factory()->create(['tenant_id' => $this->tenant->id]));
    ($this->delivery)([], $other);

    Sanctum::actingAs($this->owner);
    $answer = $this->getJson(($this->url)())->assertOk();

    expect($answer->json('data.*.id'))->toBe([$newer->public_id, $older->public_id])
        ->and($answer->json('data.0.status.key'))->toBe('failed')
        ->and($answer->json('data.0.failure.key'))->toBe('timeout')
        ->and($answer->json('data.0'))->not->toHaveKey('payload')
        ->and($answer->json('meta.statuses.*.key'))->toBe(['queued', 'delivered', 'failed'])
        ->and($answer->json('meta.events.*.key'))->toContain('card.issued', 'webhook.test');
});

it('filters by status and by event, and refuses what it does not know', function () {
    $failed = ($this->delivery)(['status' => WebhookDeliveryStatus::Failed, 'event' => 'card.issued']);
    ($this->delivery)(['status' => WebhookDeliveryStatus::Delivered, 'event' => 'card.issued']);
    $printed = ($this->delivery)(['status' => WebhookDeliveryStatus::Delivered, 'event' => 'card.printed']);

    Sanctum::actingAs($this->owner);

    expect($this->getJson(($this->url)().'?filter[status]=failed')->assertOk()->json('data.*.id'))->toBe([$failed->public_id])
        ->and($this->getJson(($this->url)().'?filter[event]=card.printed')->assertOk()->json('data.*.id'))->toBe([$printed->public_id]);

    $this->getJson(($this->url)().'?filter[status]=lost')->assertUnprocessable()->assertJsonValidationErrors('filter.status');
    $this->getJson(($this->url)().'?filter[who]=x')->assertUnprocessable();
});

it('gives what was sent as the exact text, an empty object still an object', function () {
    $text = '{"id":"x","event":"card.issued","data":{"custom_fields":{}}}';
    $delivery = ($this->delivery)(['payload' => $text]);

    Sanctum::actingAs($this->owner);

    $this->getJson(($this->url)($delivery->public_id))->assertOk()
        ->assertJsonPath('data.payload', $text)
        ->assertJsonPath('data.payload_hidden', false);
});

it('hides what was sent from someone who may not see that data, but not that it was sent', function () {
    $user = tenantUser($this->tenant, 'Viewer');
    ($this->inTenant)(fn () => $user->roles()->firstOrFail()->syncPermissions(['integrations.manage', 'cards.view']));
    $card = ($this->delivery)(['event' => 'card.issued']);
    $person = ($this->delivery)(['event' => 'cardholder.created']);
    $test = ($this->delivery)(['event' => 'webhook.test']);
    $gone = ($this->delivery)(['event' => 'badge.lost']);

    Sanctum::actingAs($user);

    $this->getJson(($this->url)($card->public_id))->assertOk()->assertJsonPath('data.payload_hidden', false);
    $this->getJson(($this->url)($test->public_id))->assertOk()->assertJsonPath('data.payload_hidden', false);
    $this->getJson(($this->url)($person->public_id))->assertOk()
        ->assertJsonPath('data.payload', null)
        ->assertJsonPath('data.payload_hidden', true)
        ->assertJsonPath('data.event.key', 'cardholder.created');
    $this->getJson(($this->url)($gone->public_id))->assertOk()->assertJsonPath('data.payload', null);

    expect($this->getJson(($this->url)())->assertOk()->json('data'))->toHaveCount(4);
});

it('finds a delivery only under its own webhook and organization', function () {
    $other = ($this->inTenant)(fn () => Webhook::factory()->create(['tenant_id' => $this->tenant->id]));
    $delivery = ($this->delivery)([], $other);
    [$foreignTenant, $foreignOwner] = tenantWithOwner('beta', 'owner@beta.example.com');

    Sanctum::actingAs($this->owner);
    $this->getJson(($this->url)($delivery->public_id))->assertNotFound();

    app('auth')->forgetGuards();
    Sanctum::actingAs($foreignOwner);
    $this->getJson(($this->url)())->assertNotFound();
    $this->getJson("/api/v1/webhooks/{$other->public_id}/deliveries/{$delivery->public_id}")->assertNotFound();
    expect($foreignTenant->id)->not->toBe($this->tenant->id);
});

it('needs the permission', function () {
    $delivery = ($this->delivery)();
    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));

    $this->getJson(($this->url)())->assertForbidden();
    $this->getJson(($this->url)($delivery->public_id))->assertForbidden();
});
