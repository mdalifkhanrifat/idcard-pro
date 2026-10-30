<?php

use App\Models\Tenant;
use App\Models\User;
use App\Models\Webhook;
use App\Services\Webhooks\WebhookAddress;
use App\Services\Webhooks\WebhookEvents;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/*
| 6.2a: where an organization wants to be told, about what, signed with a secret shown once. Saving an address sends
| nothing anywhere: it is read as written.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    [$this->tenant, $this->owner] = tenantWithOwner('alpha', 'owner@example.com');
    Sanctum::actingAs($this->owner);
    $this->good = ['name' => 'HR system', 'url' => 'https://hr.example.com/hooks', 'events' => ['cardholder.created', 'card.issued']];
    $this->webhook = fn (array $attributes = [], ?Tenant $tenant = null): Webhook => app(TenantContext::class)
        ->run($tenant ?? $this->tenant, fn () => Webhook::factory()->create(['tenant_id' => ($tenant ?? $this->tenant)->id, ...$attributes]));
});

it('makes a webhook, its secret shown once and kept encrypted', function () {
    $response = $this->postJson('/api/v1/webhooks', $this->good)->assertCreated()
        ->assertJsonPath('data.name', 'HR system')
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.events', ['cardholder.created', 'card.issued']);
    $secret = $response->json('data.secret');

    expect($secret)->toStartWith('whsec_')->and(strlen($secret))->toBe(54)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    $id = $response->json('data.id');
    $this->getJson("/api/v1/webhooks/{$id}")->assertOk()->assertJsonMissingPath('data.secret');
    expect($this->getJson('/api/v1/webhooks')->getContent())->not->toContain($secret);

    $stored = DB::table('webhooks')->where('public_id', $id)->value('secret');
    expect($stored)->not->toContain($secret)
        ->and(Webhook::where('public_id', $id)->first()->secret)->toBe($secret);
});

it('changes the secret on request, the old one gone, and says when', function () {
    $this->travelTo('2026-10-30 10:00:00');
    $webhook = ($this->webhook)();
    $old = $webhook->secret;

    $new = $this->postJson("/api/v1/webhooks/{$webhook->public_id}/secret")->assertOk()->assertJsonPath('data.secret_rotated_at', fn (?string $at) => str_starts_with((string) $at, '2026-10-30'))->json('data.secret');

    expect($new)->toStartWith('whsec_')->not->toBe($old)->and($webhook->fresh()->secret)->toBe($new);
    $rows = $this->getJson('/api/v1/audit/activities?filter[log_name]=webhooks&filter[event]=updated')->assertOk()->json('data');
    expect($rows[0]['fields'][0]['label'])->toBe('Signing secret changed')
        ->and(json_encode($rows))->not->toContain($new)->not->toContain($old);
});

it('accepts a public address and refuses what leads inside, reading it as written', function () {
    foreach (['https://hr.example.com/hooks', 'https://hr.example.com:8443/in?x=1', 'https://8.8.8.8/hook', 'https://[2606:4700:4700::1111]/hook'] as $url) {
        expect(WebhookAddress::problem($url))->toBeNull($url);
    }

    $inner = [
        'https://localhost/x', 'https://LOCALHOST/x', 'https://app/x', 'https://mysql:3306/x', 'https://redis/x', 'https://127.0.0.1/x', 'https://127.1/x',
        'https://10.0.0.5/x', 'https://172.16.0.1/x', 'https://192.168.1.1/x', 'https://169.254.169.254/latest/meta-data', 'https://0.0.0.0/x',
        'https://[::1]/x', 'https://[fd00::1]/x', 'https://[fe80::1]/x', 'https://[::ffff:127.0.0.1]/x', 'https://[::ffff:10.0.0.1]/x',
        'https://2130706433/x', 'https://0x7f.0.0.1/x', 'https://0177.0.0.1/x', 'https://foo.local/x', 'https://db.internal/x', 'https://x.localhost/x',
    ];
    foreach ($inner as $url) {
        expect(WebhookAddress::problem($url))->toBe(__('webhooks.address.inner'), $url);
    }

    expect(WebhookAddress::problem('http://hr.example.com/x'))->toBe(__('webhooks.address.https'))
        ->and(WebhookAddress::problem('https://user:pass@hr.example.com/x'))->toBe(__('webhooks.address.credentials'));
    foreach (['not an address', 'ftp://hr.example.com/x', 'file:///etc/passwd', 'gopher://x.example.com', 'https://'] as $url) {
        expect(WebhookAddress::problem($url))->toBe(__('webhooks.address.invalid'), $url);
    }

    $this->postJson('/api/v1/webhooks', [...$this->good, 'url' => 'https://169.254.169.254/x'])->assertJsonValidationErrors('url');
    $this->patchJson('/api/v1/webhooks/'.($this->webhook)()->public_id, ['url' => 'https://redis/x'])->assertJsonValidationErrors('url');
});

it('opens inner addresses only by the installation\'s own setting, for a local receiver', function () {
    config(['webhooks.allow_private' => true]);

    expect(WebhookAddress::problem('http://webhook-receiver:9000/in'))->toBeNull()
        ->and(WebhookAddress::problem('ftp://x.example.com'))->toBe(__('webhooks.address.invalid'))
        ->and(WebhookAddress::problem('https://user:pass@x.example.com'))->toBe(__('webhooks.address.credentials'));
});

it('takes only events of the registry, and names them all in both languages', function () {
    $this->postJson('/api/v1/webhooks', [...$this->good, 'events' => ['card.stolen']])->assertJsonValidationErrors('events.0');
    $this->postJson('/api/v1/webhooks', [...$this->good, 'events' => []])->assertJsonValidationErrors('events');
    $this->postJson('/api/v1/webhooks', [...$this->good, 'events' => ['card.issued', 'card.issued']])->assertJsonValidationErrors('events.0');

    foreach (['en', 'bn'] as $locale) {
        app()->setLocale($locale);
        $names = (array) __('webhooks.events');
        foreach (WebhookEvents::keys() as $event) {
            expect($names)->toHaveKey($event);
        }
        expect(array_keys($names))->toEqualCanonicalizing(WebhookEvents::keys());
    }
    app()->setLocale('en');

    $meta = $this->getJson('/api/v1/webhooks')->assertOk()->json('meta.events');
    expect(array_column($meta, 'key'))->toBe(array_keys(config('webhooks.events')))
        ->and($meta[1]['events'][0])->toBe(['key' => 'card.issued', 'name' => 'Card issued']);
});

it('takes back its own answer, and a change leaves what is not sent', function () {
    $webhook = ($this->webhook)(['name' => 'HR', 'events' => ['card.issued']]);
    $url = "/api/v1/webhooks/{$webhook->public_id}";
    $answer = $this->getJson($url)->assertOk()->json('data');

    $this->putJson($url, $answer)->assertOk()->assertJsonPath('data', $answer);
    $this->patchJson($url, ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.name', 'HR')->assertJsonPath('data.events', ['card.issued']);
    $this->patchJson($url, ['events' => ['card.revoked']])->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.events', ['card.revoked']);
    $this->patchJson($url, ['events' => []])->assertJsonValidationErrors('events');
});

it('filters and sorts the list', function () {
    ($this->webhook)(['name' => 'Beta', 'url' => 'https://beta.example.com/in']);
    ($this->webhook)(['name' => 'Alpha', 'enabled' => false]);

    $this->getJson('/api/v1/webhooks')->assertJsonPath('data.0.name', 'Alpha');
    $this->getJson('/api/v1/webhooks?sort=-name')->assertJsonPath('data.0.name', 'Beta');
    $this->getJson('/api/v1/webhooks?filter[search]=beta.example')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/webhooks?filter[enabled]=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Alpha');
    $this->getJson('/api/v1/webhooks?filter[enabled]=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Beta');
    $this->getJson('/api/v1/webhooks?filter[nope]=1')->assertJsonValidationErrors('filter');
    $this->getJson('/api/v1/webhooks?sort=secret')->assertJsonValidationErrors('sort');
});

it('keeps each organization to its own', function () {
    [$other] = tenantWithOwner('beta', 'b@example.com');
    $theirs = ($this->webhook)(['name' => 'Theirs'], $other);

    $this->getJson('/api/v1/webhooks')->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/webhooks/{$theirs->public_id}")->assertNotFound();
    $this->patchJson("/api/v1/webhooks/{$theirs->public_id}", ['name' => 'Taken'])->assertNotFound();
    $this->postJson("/api/v1/webhooks/{$theirs->public_id}/secret")->assertNotFound();
    $this->deleteJson("/api/v1/webhooks/{$theirs->public_id}")->assertNotFound();
});

it('follows the permission, and an account\'s token cannot reach it', function (string $role, bool $allowed) {
    $webhook = ($this->webhook)();
    Sanctum::actingAs(tenantUser($this->tenant, $role));

    $this->getJson('/api/v1/webhooks')->assertStatus($allowed ? 200 : 403);
    $this->getJson("/api/v1/webhooks/{$webhook->public_id}")->assertStatus($allowed ? 200 : 403);
    $this->postJson('/api/v1/webhooks', $this->good)->assertStatus($allowed ? 201 : 403);
    $this->patchJson("/api/v1/webhooks/{$webhook->public_id}", ['name' => 'x'])->assertStatus($allowed ? 200 : 403);
    $this->postJson("/api/v1/webhooks/{$webhook->public_id}/secret")->assertStatus($allowed ? 200 : 403);
    $this->deleteJson("/api/v1/webhooks/{$webhook->public_id}")->assertStatus($allowed ? 204 : 403);
})->with([['Administrator', true], ['Operator', false], ['Viewer', false]]);

it('is for people, whatever an account\'s role', function () {
    $this->postJson('/api/v1/roles', ['name' => 'Wide', 'permissions' => ['integrations.manage']])->assertCreated();
    $account = app(TenantContext::class)->run($this->tenant, fn () => User::factory()->integration()->create(['tenant_id' => $this->tenant->id])->assignRole('Wide'));
    Sanctum::actingAs($account->fresh());

    $this->getJson('/api/v1/webhooks')->assertForbidden()->assertJsonPath('message', __('integrations.person_only'));
});

it('asks to be signed in', function () {
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/webhooks')->assertUnauthorized();
});

it('stops at the most an organization may have, counting no deleted one', function () {
    config(['webhooks.max_per_organization' => 2]);
    ($this->webhook)();
    $second = ($this->webhook)();

    $this->postJson('/api/v1/webhooks', $this->good)->assertUnprocessable()->assertJsonPath('message', __('webhooks.too_many', ['max' => 2]));
    $this->deleteJson("/api/v1/webhooks/{$second->public_id}")->assertNoContent();
    $this->postJson('/api/v1/webhooks', $this->good)->assertCreated();
});

it('keeps every change in the audit log, named and never the secret', function () {
    $made = $this->postJson('/api/v1/webhooks', $this->good)->assertCreated()->json('data');
    $this->patchJson("/api/v1/webhooks/{$made['id']}", ['enabled' => false, 'events' => ['card.revoked']])->assertOk();
    $this->deleteJson("/api/v1/webhooks/{$made['id']}")->assertNoContent();

    $rows = $this->getJson('/api/v1/audit/activities?filter[log_name]=webhooks')->assertOk()->json('data');
    $change = collect($rows)->firstWhere('event', 'updated');
    $byKey = collect($change['fields'])->keyBy('key');

    expect(collect($rows)->pluck('event')->all())->toBe(['deleted', 'updated', 'created'])
        ->and($byKey['events'])->toMatchArray(['label' => 'Events', 'old' => 'Cardholder added, Card issued', 'new' => 'Card revoked'])
        ->and($byKey['enabled']['label'])->toBe('On')
        ->and(json_encode($rows))->not->toContain($made['secret']);
    expect($this->getJson('/api/v1/audit/activities')->json('meta.log_names'))->toContain('webhooks');
});
