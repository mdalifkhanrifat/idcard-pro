<?php

use App\Models\Webhook;
use App\Services\Webhooks\WebhookHostResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/*
| A limit written in place (throttle:5,1) counts per person, not per route: without a name of its own every such
| route spends the same count, and five PDFs use up the five webhook tests.
*/

/**
 * @return Collection<int, array{uri: string, limit: string}>
 */
function inPlaceThrottles(): Collection
{
    return collect(Route::getRoutes()->getRoutes())->flatMap(fn (RoutingRoute $route) => collect($route->gatherMiddleware())
        ->filter(fn ($middleware): bool => is_string($middleware) && preg_match('/^throttle:\d+,\d+/', $middleware) === 1)
        ->map(fn (string $middleware): array => ['uri' => $route->uri(), 'limit' => $middleware]));
}

it('gives every limit written in place a name of its own', function () {
    $throttles = inPlaceThrottles();

    expect($throttles)->not->toBeEmpty()
        ->and($throttles->reject(fn (array $throttle): bool => substr_count($throttle['limit'], ',') === 2)->pluck('uri')->all())->toBe([])
        ->and($throttles->pluck('limit')->map(fn (string $limit): string => explode(',', $limit)[2] ?? '')->duplicates()->all())->toBe([]);
});

it('says when to try again in the reader\'s language and digits, keeping the headers', function () {
    Queue::fake();
    [, $owner] = tenantWithOwner();
    $owner->forceFill(['locale' => 'bn'])->save();
    Sanctum::actingAs($owner);

    foreach (range(0, (int) config('notifications.manual.test_per_minute')) as $try) {
        // Each request starts as a new one would: a language left by the one before would hide a limit that runs too early
        app()->setLocale('en');
        $answer = $this->postJson('/api/v1/notifications/test', ['channel' => 'mail', 'address' => 'qa@example.test'], ['Accept-Language' => 'bn']);
    }

    $answer->assertTooManyRequests();
    expect($answer->json('message'))->toMatch('/^অনেকবার চেষ্টা হয়েছে। [০-৯]+ সেকেন্ড পরে/u')
        ->and((int) $answer->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($answer->headers->has('X-RateLimit-Limit'))->toBeTrue();
});

it('does not let one action use up the limit of another', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('', 200)]);
    Queue::fake();
    [$tenant, $owner] = tenantWithOwner();
    $this->mock(WebhookHostResolver::class, fn ($mock) => $mock->shouldReceive('addresses')->andReturn(['93.184.216.34']));
    $webhook = app(TenantContext::class)->run($tenant, fn () => Webhook::factory()->create(['tenant_id' => $tenant->id]));
    Sanctum::actingAs($owner);

    foreach (range(1, (int) config('webhooks.test_per_minute')) as $try) {
        $this->postJson("/api/v1/webhooks/{$webhook->public_id}/test")->assertCreated();
    }
    $this->postJson("/api/v1/webhooks/{$webhook->public_id}/test")->assertTooManyRequests();

    $this->postJson('/api/v1/notifications/test', ['channel' => 'mail', 'address' => 'qa@example.test'])->assertCreated();
});
