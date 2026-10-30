<?php

use App\Enums\WebhookFailure;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookAddress;
use App\Services\Webhooks\WebhookHostResolver;
use App\Services\Webhooks\WebhookSender;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/*
| 6.2b: one attempt to tell an address something. No test here reaches the network: every name resolves through a
| stand-in, and a request nobody faked fails the test.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    [$this->tenant, $this->owner] = tenantWithOwner('alpha', 'owner@example.com');
    $this->resolves = function (array $addresses): void {
        $this->mock(WebhookHostResolver::class, fn ($mock) => $mock->shouldReceive('addresses')->andReturn($addresses));
    };
    $this->attempt = function (string $url = 'https://hr.example.com/hooks', array $payload = []): array {
        return app(TenantContext::class)->run($this->tenant, function () use ($url, $payload) {
            $webhook = Webhook::factory()->create(['tenant_id' => $this->tenant->id, 'url' => $url, 'secret' => 'whsec_test']);
            $delivery = WebhookDelivery::factory()->create(['tenant_id' => $this->tenant->id, 'webhook_id' => $webhook->id, 'event' => 'card.issued', ...($payload === [] ? [] : ['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)])]);

            return [app(WebhookSender::class)->send($webhook, $delivery), $delivery];
        });
    };
});

it('signs the exact text it sends, with the time inside, so a receiver can check both', function () {
    ($this->resolves)(['93.184.216.34']);
    Http::fake(['*' => Http::response('ok', 200)]);
    $this->travelTo('2026-10-30 10:00:00');

    [$result, $delivery] = ($this->attempt)(payload: ['id' => 'x', 'name' => 'রহিম উদ্দিন', 'url' => 'https://a.test/b']);

    expect($result->delivered())->toBeTrue()->and($result->code)->toBe(200);
    Http::assertSent(function (Request $request) use ($delivery) {
        $timestamp = (string) now()->getTimestamp();
        expect($request->body())->toBe('{"id":"x","name":"রহিম উদ্দিন","url":"https://a.test/b"}')
            ->and($request->url())->toBe('https://hr.example.com/hooks')
            ->and($request->method())->toBe('POST')
            ->and($request->header('Content-Type'))->toBe(['application/json'])
            ->and($request->header('X-Webhook-Id'))->toBe([$delivery->public_id])
            ->and($request->header('X-Webhook-Event'))->toBe(['card.issued'])
            ->and($request->header('X-Webhook-Timestamp'))->toBe([$timestamp])
            ->and($request->header('X-Webhook-Signature'))->toBe(['sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'whsec_test')]);

        return true;
    });
});

it('connects to exactly the addresses it checked, and follows nothing', function () {
    ($this->resolves)(['93.184.216.34', '2606:2800:220:1::1']);
    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen = $options;

        return Http::response('', 200);
    });

    ($this->attempt)('https://hr.example.com:8443/hooks');

    expect($seen['curl'][CURLOPT_RESOLVE])->toBe(['hr.example.com:8443:93.184.216.34,[2606:2800:220:1::1]'])
        ->and($seen['allow_redirects'])->toBeFalse()
        ->and($seen['proxy'])->toBe('')
        ->and($seen['sink'])->toBe('/dev/null')
        ->and($seen['connect_timeout'])->toBe(config('webhooks.connect_timeout'))
        ->and($seen['timeout'])->toBe(config('webhooks.timeout'));
});

it('takes the port from the scheme, and pins nothing for an address that is already an IP', function () {
    config(['webhooks.allow_private' => true]);
    ($this->resolves)(['10.0.0.5']);
    $pins = [];
    Http::fake(function (Request $request, array $options) use (&$pins) {
        $pins[] = $options['curl'][CURLOPT_RESOLVE] ?? null;

        return Http::response('', 200);
    });

    ($this->attempt)('http://receiver/hooks');
    ($this->attempt)('https://hr.example.com/hooks');
    ($this->attempt)('https://8.8.8.8/hooks');

    expect($pins)->toBe([['receiver:80:10.0.0.5'], ['hr.example.com:443:10.0.0.5'], null]);
});

it('sends nothing when any address of a name leads inside, the name looking public', function () {
    Http::fake();

    foreach ([['10.0.0.5'], ['93.184.216.34', '127.0.0.1'], ['169.254.169.254'], ['93.184.216.34', 'fd00::1'], ['2002:7f00:1::1']] as $addresses) {
        ($this->resolves)($addresses);
        [$result] = ($this->attempt)();
        expect($result->failure)->toBe(WebhookFailure::Refused, implode(',', $addresses))->and($result->code)->toBeNull();
    }

    Http::assertNothingSent();
});

it('sends nothing to a name that cannot be found', function () {
    ($this->resolves)([]);
    Http::fake();

    [$result] = ($this->attempt)();

    expect($result->failure)->toBe(WebhookFailure::Unresolved);
    Http::assertNothingSent();
});

it('checks the address again at sending: the rules may be tighter than when it was saved', function () {
    ($this->resolves)(['93.184.216.34']);
    Http::fake();

    foreach (['http://hr.example.com/hooks', 'https://localhost/hooks', 'https://10.0.0.5/hooks', 'https://[::ffff:127.0.0.1]/hooks'] as $url) {
        [$result] = ($this->attempt)($url);
        expect($result->failure)->toBe(WebhookFailure::Refused, $url);
    }

    Http::assertNothingSent();
});

it('counts only 2xx as told, and says what else the address answered', function () {
    ($this->resolves)(['93.184.216.34']);

    $status = 200;
    Http::fake(function () use (&$status) {
        return Http::response('', $status, ['Location' => 'http://169.254.169.254/']);
    });

    foreach ([[201, null], [204, null], [301, WebhookFailure::Redirected], [302, WebhookFailure::Redirected], [400, WebhookFailure::Rejected], [404, WebhookFailure::Rejected], [500, WebhookFailure::Rejected], [503, WebhookFailure::Rejected]] as [$status, $failure]) {
        [$result] = ($this->attempt)();
        expect($result->failure)->toBe($failure, (string) $status)->and($result->code)->toBe($status);
    }
});

it('names a connection failure from a short list, keeping none of its words', function () {
    ($this->resolves)(['93.184.216.34']);
    $error = 0;
    Http::fake(function () use (&$error) {
        throw new ConnectionException("cURL error {$error}: Failed to connect to 10.1.2.3 port 5432");
    });

    foreach ([
        [28, WebhookFailure::Timeout],
        [60, WebhookFailure::Certificate],
        [58, WebhookFailure::Certificate],
        [7, WebhookFailure::Unreachable],
        [52, WebhookFailure::Unreachable],
    ] as [$error, $failure]) {
        [$result] = ($this->attempt)();
        expect($result->failure)->toBe($failure, (string) $error)->and($result->code)->toBeNull();
    }
});

it('names every failure in both languages', function () {
    foreach (['en', 'bn'] as $locale) {
        app()->setLocale($locale);
        foreach (WebhookFailure::cases() as $failure) {
            expect($failure->label())->not->toStartWith('webhooks.', $failure->value);
        }
    }
});

it('tells public from inner addresses, an IPv4 address carried in IPv6 included', function () {
    foreach (['8.8.8.8', '93.184.216.34', '2606:4700:4700::1111'] as $ip) {
        expect(WebhookAddress::isPublic($ip))->toBeTrue($ip);
    }
    foreach ([
        '127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.100.100.200', '0.0.0.0', '198.18.0.1',
        '224.0.0.1', '239.255.255.250', '240.0.0.1', '192.0.0.170', '192.88.99.1', '192.0.2.1',
        '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'fec0::1', 'ff02::1', '100::1', '2001:db8::1',
        '::ffff:127.0.0.1', '0:0:0:0:0:ffff:7f00:1', '::ffff:8.8.8.8', '::127.0.0.1', '2002:7f00:1::1', '64:ff9b::7f00:1', '64:ff9b:1::1', '2001:0:4136:e378::1',
        'not an ip',
    ] as $ip) {
        expect(WebhookAddress::isPublic($ip))->toBeFalse($ip);
    }
});

it('sends only to the installation\'s own hosts when it lists any, whatever is saved', function () {
    Sanctum::actingAs($this->owner);
    config(['webhooks.allow_private' => true, 'webhooks.only_hosts' => ['receiver']]);
    ($this->resolves)(['172.18.0.5']);
    Http::fake(['*' => Http::response('', 200)]);

    $this->postJson('/api/v1/webhooks', ['name' => 'Out', 'url' => 'https://hr.example.com/hooks', 'events' => ['card.issued']])
        ->assertUnprocessable()->assertJsonValidationErrors(['url']);
    $this->postJson('/api/v1/webhooks', ['name' => 'Mine', 'url' => 'http://RECEIVER:9099/hooks', 'events' => ['card.issued']])->assertCreated();

    [$out] = ($this->attempt)('https://hr.example.com/hooks');
    [$mine] = ($this->attempt)('http://receiver:9099/hooks');

    expect($out->failure)->toBe(WebhookFailure::Refused)->and($mine->delivered())->toBeTrue();
    Http::assertSentCount(1);
});

it('lets any host through when the installation lists none', function () {
    expect(config('webhooks.only_hosts'))->toBe([])
        ->and(WebhookAddress::problem('https://hr.example.com/x'))->toBeNull();
});

it('keeps a payload as the exact text written, and a deleted webhook readable', function () {
    ($this->resolves)(['93.184.216.34']);
    Http::fake(['*' => Http::response('', 200)]);
    [, $delivery] = ($this->attempt)();
    $text = '{"z":1,"custom_fields":{},"a":"রহিম"}';

    $fresh = app(TenantContext::class)->run($this->tenant, function () use ($delivery, $text) {
        $delivery->update(['payload' => $text]);

        return WebhookDelivery::query()->findOrFail($delivery->id);
    });
    expect($fresh->payload)->toBe($text)
        ->and($fresh->status->value)->toBe('queued')
        ->and($fresh->attempts)->toBe(0);

    app(TenantContext::class)->run($this->tenant, function () use ($fresh) {
        $fresh->webhook->delete();
        expect($fresh->fresh()->webhook->name)->not->toBe('');
    });
});
