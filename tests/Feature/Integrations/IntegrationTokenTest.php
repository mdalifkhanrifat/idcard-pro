<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/*
| 6.1b: an account's API tokens, used for real (a bearer token, no signed-in test user): shown once, ending on their
| day, with the account's role and organization, and every one made or revoked in the audit log.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner('alpha', 'owner@example.com');
    Sanctum::actingAs($this->owner);
    $this->account = fn (array $attributes = [], string $role = 'Viewer', ?Tenant $tenant = null): User => app(TenantContext::class)
        ->run($tenant ?? $this->tenant, fn () => User::factory()->integration()->create(['tenant_id' => ($tenant ?? $this->tenant)->id, ...$attributes])->assignRole($role));
    $this->url = fn (User $account) => "/api/v1/integrations/{$account->public_id}/tokens";
    // The next request carries this token and no one signed in
    $this->bearer = function (string $token) {
        app('auth')->forgetGuards();

        return $this->withToken($token);
    };
    $this->asOwner = function (): void {
        app('auth')->forgetGuards();
        $this->withoutToken();
        Sanctum::actingAs($this->owner);
    };
    // The organization form, with the rest of it as it is
    $this->save = function (array $integrations) {
        $response = $this->postJson('/api/v1/settings', ['name' => 'Alpha', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233', 'integrations' => $integrations]);
        $this->owner->unsetRelation('tenant');

        return $response;
    };
    $this->issue = fn (User $account, array $data = ['name' => 'Main']) => $this->postJson(($this->url)($account), $data)->assertCreated()->json('data');
});

it('shows a token once, at its making, and never again', function () {
    $this->travelTo('2026-10-30 10:00:00');
    $account = ($this->account)();

    $response = $this->postJson(($this->url)($account), ['name' => 'Main'])->assertCreated()
        ->assertJsonPath('data.name', 'Main')
        ->assertJsonPath('data.expired', false)
        ->assertJsonPath('data.last_used_at', null);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->json('data.plain_text_token'))->toContain('|')
        ->and($response->json('data.expires_at'))->toStartWith('2027-10-30');

    $list = $this->getJson(($this->url)($account))->assertOk()->assertJsonCount(1, 'data');
    expect(array_keys($list->json('data.0')))->toEqual(['id', 'name', 'expires_at', 'expired', 'last_used_at', 'created_at'])
        ->and($list->getContent())->not->toContain(explode('|', $response->json('data.plain_text_token'))[1]);
    expect(PersonalAccessToken::query()->first()->token)->not->toBe(explode('|', $response->json('data.plain_text_token'))[1]);
});

it('lasts as the organization says, never without an end', function () {
    $this->travelTo('2026-10-30 10:00:00');
    $account = ($this->account)();

    expect(($this->issue)($account, ['name' => 'Short', 'expires_in_days' => 30])['expires_at'])->toStartWith('2026-11-29');
    $this->postJson(($this->url)($account), ['name' => 'Long', 'expires_in_days' => 1096])->assertJsonValidationErrors('expires_in_days');
    $this->postJson(($this->url)($account), ['name' => 'Endless', 'expires_in_days' => null])->assertJsonValidationErrors('expires_in_days');

    ($this->save)(['token_days' => 90, 'max_token_days' => 180])->assertOk();
    expect(($this->issue)($account)['expires_at'])->toStartWith('2027-01-28');
    $this->postJson(($this->url)($account), ['name' => 'Long', 'expires_in_days' => 181])->assertJsonValidationErrors('expires_in_days');
});

it('keeps the organization\'s token lengths apart from its other settings, and in order', function () {
    $this->getJson('/api/v1/settings')->assertOk()->assertJsonPath('data.integrations', ['token_days' => 365, 'max_token_days' => 1095, 'requests_per_minute' => 600, 'sync_fields' => []]);

    ($this->save)(['token_days' => 200, 'max_token_days' => 100])->assertJsonValidationErrors('integrations.token_days');
    ($this->save)(['token_days' => 2000])->assertJsonValidationErrors('integrations.token_days');
    ($this->save)(['max_token_days' => 0])->assertJsonValidationErrors('integrations.max_token_days');

    // Only what is sent changes, and the usual length follows the most down
    ($this->save)(['max_token_days' => 100])->assertOk()->assertJsonPath('data.integrations', ['token_days' => 100, 'max_token_days' => 100, 'requests_per_minute' => 600, 'sync_fields' => []]);
    ($this->save)($this->getJson('/api/v1/settings')->json('data.integrations'))->assertOk();

    $fields = collect($this->getJson('/api/v1/audit/activities?filter[log_name]=tenant&filter[event]=updated')->json('data.0.fields'))->keyBy('key');
    expect($fields['settings.integrations.max_token_days']['label'])->toBe('Longest an integration token may last (days)');
});

it('works in its own organization with its own role, and says when it was used', function () {
    [$other] = tenantWithOwner('beta', 'b@example.com');
    cardholder($this->tenant, ['full_name' => 'Ours']);
    cardholder($other, ['full_name' => 'Theirs']);
    $account = ($this->account)();
    $plain = ($this->issue)($account)['plain_text_token'];

    ($this->bearer)($plain)->getJson('/api/v1/cardholders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Ours');
    ($this->bearer)($plain)->postJson('/api/v1/cardholders', ['full_name' => 'Not allowed'])->assertForbidden();

    ($this->asOwner)();
    $this->getJson(($this->url)($account))->assertJsonPath('data.0.last_used_at', fn (?string $at) => $at !== null);
    $this->getJson("/api/v1/integrations/{$account->public_id}")->assertJsonPath('data.tokens_count', 1)
        ->assertJsonPath('data.last_used_at', fn (?string $at) => $at !== null);
    $this->getJson('/api/v1/integrations')->assertJsonPath('data.0.tokens_count', 1);
});

it('stops on its last day, when revoked, while the account is off, and with the account', function () {
    $account = ($this->account)();
    $plain = ($this->issue)($account, ['name' => 'Short', 'expires_in_days' => 1])['plain_text_token'];

    ($this->bearer)($plain)->getJson('/api/v1/cardholders')->assertOk();
    $this->travel(25)->hours();
    ($this->bearer)($plain)->getJson('/api/v1/cardholders')->assertUnauthorized();
    ($this->asOwner)();
    $this->getJson(($this->url)($account))->assertJsonPath('data.0.expired', true);
    $this->getJson("/api/v1/integrations/{$account->public_id}")->assertJsonPath('data.tokens_count', 0);

    $token = ($this->issue)($account);
    ($this->bearer)($token['plain_text_token'])->getJson('/api/v1/cardholders')->assertOk();
    ($this->asOwner)();
    $this->deleteJson(($this->url)($account)."/{$token['id']}")->assertNoContent();
    ($this->bearer)($token['plain_text_token'])->getJson('/api/v1/cardholders')->assertUnauthorized();

    ($this->asOwner)();
    $token = ($this->issue)($account);
    $this->patchJson("/api/v1/integrations/{$account->public_id}", ['status' => 'inactive'])->assertOk();
    ($this->bearer)($token['plain_text_token'])->getJson('/api/v1/cardholders')->assertForbidden();

    ($this->asOwner)();
    $this->deleteJson("/api/v1/integrations/{$account->public_id}")->assertNoContent();
    ($this->bearer)($token['plain_text_token'])->getJson('/api/v1/cardholders')->assertUnauthorized();
});

it('takes the same reach as giving the account its role, while anyone managing can stop one', function () {
    $this->postJson('/api/v1/roles', ['name' => 'Settings keeper', 'permissions' => ['settings.update']])->assertCreated();
    $strong = ($this->account)(['name' => 'Strong'], 'Settings keeper');
    $token = ($this->issue)($strong);
    $within = ($this->account)(['name' => 'Within']);

    Sanctum::actingAs(tenantUser($this->tenant, 'Administrator'));

    $this->postJson(($this->url)($strong), ['name' => 'Mine now'])->assertUnprocessable()->assertJsonPath('message', __('roles.beyond_own'));
    ($this->issue)($within);
    $this->deleteJson(($this->url)($strong)."/{$token['id']}")->assertNoContent();
});

it('follows the permission, and keeps to the organization and the account', function () {
    [$other] = tenantWithOwner('beta', 'b@example.com');
    $theirs = ($this->account)([], 'Viewer', $other);
    $ours = ($this->account)();
    $another = ($this->account)();
    $token = ($this->issue)($another);

    $this->getJson(($this->url)($theirs))->assertNotFound();
    $this->postJson(($this->url)($theirs), ['name' => 'Taken'])->assertNotFound();
    $this->getJson(($this->url)($this->owner))->assertNotFound();

    // Another account's token is not reached through this one
    $this->deleteJson(($this->url)($ours)."/{$token['id']}")->assertNoContent();
    expect($another->tokens()->count())->toBe(1);
    $this->deleteJson(($this->url)($ours).'/not-a-number')->assertNotFound();

    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));
    $this->getJson(($this->url)($ours))->assertForbidden();
    $this->postJson(($this->url)($ours), ['name' => 'x'])->assertForbidden();
    $this->deleteJson(($this->url)($another)."/{$token['id']}")->assertForbidden();

    // An account's own token cannot make it another
    $this->postJson('/api/v1/roles', [])->assertForbidden();
    ($this->asOwner)();
    $this->postJson('/api/v1/roles', ['name' => 'Wide', 'permissions' => ['integrations.manage']])->assertCreated();
    $wide = ($this->account)(['name' => 'Wide'], 'Wide');
    $plain = ($this->issue)($wide)['plain_text_token'];
    ($this->bearer)($plain)->postJson(($this->url)($wide), ['name' => 'More'])->assertForbidden();
    ($this->bearer)($plain)->postJson('/api/v1/auth/tokens', ['name' => 'More'])->assertForbidden();
});

it('keeps every token made or revoked in the audit log, a person\'s too, never the token', function () {
    $this->travelTo('2026-10-30 10:00:00');
    $account = ($this->account)(['name' => 'Payroll']);
    $token = ($this->issue)($account, ['name' => 'Main', 'expires_in_days' => 30]);
    $this->deleteJson(($this->url)($account)."/{$token['id']}")->assertNoContent();

    $mine = $this->postJson('/api/v1/auth/tokens', ['name' => 'My script'])->assertCreated()->json('data');
    $this->deleteJson("/api/v1/auth/tokens/{$mine['id']}")->assertNoContent();
    $this->deleteJson('/api/v1/auth/tokens/999999')->assertNoContent();

    $rows = $this->getJson('/api/v1/audit/activities?filter[log_name]=api_tokens')->assertOk()->assertJsonCount(4, 'data')->json('data');
    $made = collect($rows)->firstWhere('event', 'created');
    expect(collect($rows)->pluck('event')->all())->toBe(['deleted', 'created', 'deleted', 'created'])
        ->and($rows[3]['causer']['name'])->toBe($this->owner->name)
        ->and(collect($rows[3]['fields'])->pluck('new', 'label')->all())->toBe(['Account' => 'Payroll', 'API token name' => 'Main', 'Last day' => '29/11/2026 10:00'])
        ->and($made['fields'][1]['new'])->toBe('My script')
        ->and(json_encode($rows))->not->toContain(explode('|', $token['plain_text_token'])[1]);

    expect($this->getJson('/api/v1/audit/activities')->json('meta.log_names'))->toContain('api_tokens');
});

it('ends a deleted person\'s tokens, in the audit log too', function () {
    $operator = tenantUser($this->tenant, 'Operator');
    $operator->createToken('Script');

    $this->deleteJson("/api/v1/users/{$operator->public_id}")->assertNoContent();

    expect($operator->tokens()->count())->toBe(0);
    $this->getJson('/api/v1/audit/activities?filter[log_name]=api_tokens&filter[event]=deleted')->assertJsonCount(1, 'data');
});
