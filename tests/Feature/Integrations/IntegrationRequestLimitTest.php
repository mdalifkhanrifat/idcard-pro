<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\Integrations\IntegrationSettings;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

/*
| 6.2e-1: how many API calls a system may make a minute, as its organization chose. Per account, so a second token adds
| nothing; people in a browser are not limited. Real bearer tokens: the fake token of Sanctum::actingAs carries none.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner('alpha', 'owner@example.com');
    config(['integrations.requests.min' => 2]);
    $this->limit = function (Tenant $tenant, int $perMinute): void {
        $tenant->forceFill(['settings' => [...($tenant->settings ?? []), 'integrations' => ['requests_per_minute' => $perMinute]]])->save();
    };
    $this->account = fn (Tenant $tenant, string $name = 'HR'): User => app(TenantContext::class)
        ->run($tenant, fn () => User::factory()->integration()->create(['tenant_id' => $tenant->id, 'name' => $name])->assignRole('Viewer'));
    $this->token = fn (User $account, string $name = 'Main'): string => $account->createToken($name, ['*'], now()->addYear())->plainTextToken;
    // One call with a token and no one signed in
    $this->call = function (string $token) {
        app('auth')->forgetGuards();
        app(TenantContext::class)->set(null);

        return $this->withToken($token)->getJson('/api/v1/auth/me');
    };
});

it('lets a system make as many calls a minute as its organization chose, then says when to try again', function () {
    ($this->limit)($this->tenant, 3);
    $token = ($this->token)(($this->account)($this->tenant));

    foreach ([2, 1, 0] as $left) {
        $answer = ($this->call)($token)->assertOk();
        expect($answer->headers->get('X-RateLimit-Limit'))->toBe('3')
            ->and($answer->headers->get('X-RateLimit-Remaining'))->toBe((string) $left);
    }

    $answer = ($this->call)($token)->assertTooManyRequests();
    expect((int) $answer->headers->get('Retry-After'))->toBeGreaterThan(0);

    $this->travel(61)->seconds();
    ($this->call)($token)->assertOk();
});

it('counts an account as one, however many tokens it has', function () {
    ($this->limit)($this->tenant, 2);
    $account = ($this->account)($this->tenant);
    $first = ($this->token)($account, 'First');
    $second = ($this->token)($account, 'Second');

    ($this->call)($first)->assertOk();
    ($this->call)($second)->assertOk();
    ($this->call)($first)->assertTooManyRequests();
    ($this->call)($second)->assertTooManyRequests();
});

it('keeps each system to its own count, and each organization to its own number', function () {
    [$beta] = tenantWithOwner('beta', 'owner@beta.example.com');
    ($this->limit)($this->tenant, 2);
    ($this->limit)($beta, 3);
    $full = ($this->token)(($this->account)($this->tenant, 'HR'));
    $neighbour = ($this->token)(($this->account)($this->tenant, 'Doors'));
    $other = ($this->token)(($this->account)($beta));

    ($this->call)($full)->assertOk();
    ($this->call)($full)->assertOk();
    ($this->call)($full)->assertTooManyRequests();

    ($this->call)($neighbour)->assertOk();
    expect(($this->call)($other)->assertOk()->headers->get('X-RateLimit-Limit'))->toBe('3');
});

it('covers a person\'s own token, but no one in a browser', function () {
    ($this->limit)($this->tenant, 2);
    $token = $this->owner->createToken('Script', ['*'], now()->addYear())->plainTextToken;

    ($this->call)($token)->assertOk();
    ($this->call)($token)->assertOk();
    ($this->call)($token)->assertTooManyRequests();

    app('auth')->forgetGuards();
    $this->withoutToken();
    Sanctum::actingAs($this->owner);
    foreach (range(1, 4) as $try) {
        $answer = $this->getJson('/api/v1/auth/me')->assertOk();
        expect($answer->headers->has('X-RateLimit-Limit'))->toBeFalse();
    }
});

it('holds someone of no organization to the installation\'s number', function () {
    config(['integrations.requests.per_minute' => 2]);
    $admin = User::factory()->create(['tenant_id' => null, 'is_platform_admin' => true]);
    $token = ($this->token)($admin);

    ($this->call)($token)->assertOk();
    ($this->call)($token)->assertOk();
    ($this->call)($token)->assertTooManyRequests();
});

it('stays within what the installation allows, whatever was saved', function () {
    config(['integrations.requests.max' => 50]);

    expect(IntegrationSettings::normalize(['requests_per_minute' => 900])['requests_per_minute'])->toBe(50)
        ->and(IntegrationSettings::normalize(['requests_per_minute' => 1])['requests_per_minute'])->toBe(2)
        ->and(IntegrationSettings::normalize([])['requests_per_minute'])->toBe(50);
});

it('is chosen on the organization settings, noted, and told on the integrations list', function () {
    Sanctum::actingAs($this->owner);
    $save = fn (array $integrations) => $this->postJson('/api/v1/settings', ['name' => 'Alpha', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233', 'integrations' => $integrations]);

    $this->getJson('/api/v1/settings')->assertOk()
        ->assertJsonPath('data.integrations.requests_per_minute', 600)
        ->assertJsonPath('data.integration_options.requests_per_minute', ['min' => 2, 'max' => 6000]);

    $save(['requests_per_minute' => 1200])->assertOk()->assertJsonPath('data.integrations.requests_per_minute', 1200);
    $save(['requests_per_minute' => 1])->assertUnprocessable()->assertJsonValidationErrors('integrations.requests_per_minute');
    $save(['requests_per_minute' => 6001])->assertUnprocessable()->assertJsonValidationErrors('integrations.requests_per_minute');
    $save(['token_days' => 30])->assertOk()->assertJsonPath('data.integrations.requests_per_minute', 1200);

    $this->owner->unsetRelation('tenant');
    $this->getJson('/api/v1/integrations')->assertOk()->assertJsonPath('meta.requests_per_minute', 1200);
});
