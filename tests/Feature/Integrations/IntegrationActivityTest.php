<?php

use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

/*
| 6.1b: what an account's token does shows as that account's, from where it may work, and bookings say they came
| through the API. 16 October 2026 is a Friday in Dhaka; the place's week is Sunday to Thursday, 9 to 5.
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$this->tenant, $this->owner] = tenantWithOwner('alpha', 'owner@example.com');
    Sanctum::actingAs($this->owner);
    $this->inTenant = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    $this->account = fn (array $attributes = [], string $role = 'Administrator'): User => ($this->inTenant)(
        fn () => User::factory()->integration()->create(['tenant_id' => $this->tenant->id, 'name' => 'Payroll', ...$attributes])->assignRole($role)
    );
    $this->token = fn (User $account): string => $account->createToken('Main', ['*'], now()->addDays(30))->plainTextToken;
    $this->bearer = function (string $token, string $ip = '198.51.100.20') {
        app('auth')->forgetGuards();

        return $this->withToken($token)->withServerVariables(['REMOTE_ADDR' => $ip]);
    };
    $this->asOwner = function (): void {
        app('auth')->forgetGuards();
        $this->withoutToken();
        Sanctum::actingAs($this->owner);
    };
});

it('works only from the addresses it names, any when it names none', function () {
    $account = ($this->account)(['allowed_ips' => ['203.0.113.7', '198.51.100.0/24', '2001:db8::/32']]);
    $plain = ($this->token)($account);

    ($this->bearer)($plain, '203.0.113.7')->getJson('/api/v1/cardholders')->assertOk();
    ($this->bearer)($plain, '198.51.100.200')->getJson('/api/v1/cardholders')->assertOk();
    ($this->bearer)($plain, '2001:db8::1')->getJson('/api/v1/cardholders')->assertOk();
    ($this->bearer)($plain, '203.0.113.8')->getJson('/api/v1/cardholders')->assertForbidden()->assertJsonPath('message', __('integrations.address_refused'));
    ($this->bearer)($plain, '2001:db9::1')->getJson('/api/v1/cardholders')->assertForbidden();

    ($this->asOwner)();
    $this->patchJson("/api/v1/integrations/{$account->public_id}", ['allowed_ips' => []])->assertOk()->assertJsonPath('data.allowed_ips', []);
    ($this->bearer)($plain, '203.0.113.8')->getJson('/api/v1/cardholders')->assertOk();
});

it('notes a refused address on the sign-in history once in a while, not on every request', function () {
    $account = ($this->account)(['allowed_ips' => ['203.0.113.7']]);
    $plain = ($this->token)($account);
    RateLimiter::clear("integration-address:{$account->id}:198.51.100.20");

    foreach ([1, 2, 3] as $try) {
        ($this->bearer)($plain)->getJson('/api/v1/cardholders')->assertForbidden();
    }
    ($this->asOwner)();
    $this->getJson('/api/v1/audit/logins?filter[channel]=api')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event', 'blocked')
        ->assertJsonPath('data.0.channel.key', 'api')
        ->assertJsonPath('data.0.ip_address', '198.51.100.20')
        ->assertJsonPath('data.0.user.name', 'Payroll');

    // Each use otherwise stays on the token, not here
    ($this->bearer)($plain, '203.0.113.7')->getJson('/api/v1/cardholders')->assertOk();
    ($this->asOwner)();
    $this->getJson('/api/v1/audit/logins?filter[channel]=api')->assertJsonCount(1, 'data');
});

it('checks the addresses it is given, and keeps them with the rest of the account', function () {
    $account = ($this->account)();
    $url = "/api/v1/integrations/{$account->public_id}";

    foreach (['not an address', '203.0.113.300', '203.0.113.0/33', '2001:db8::/129', '203.0.113.0/x'] as $wrong) {
        $this->patchJson($url, ['allowed_ips' => [$wrong]])->assertJsonValidationErrors('allowed_ips.0');
    }
    $this->patchJson($url, ['allowed_ips' => ['203.0.113.7', '203.0.113.7']])->assertJsonValidationErrors('allowed_ips.0');

    $this->patchJson($url, ['allowed_ips' => ['203.0.113.7', '198.51.100.0/24']])->assertOk();
    $this->patchJson($url, ['description' => 'Unchanged list'])->assertOk()->assertJsonPath('data.allowed_ips', ['203.0.113.7', '198.51.100.0/24']);
    $answer = $this->getJson($url)->json('data');
    $this->putJson($url, $answer)->assertOk()->assertJsonPath('data', $answer);

    $this->patchJson($url, ['allowed_ips' => null])->assertOk()->assertJsonPath('data.allowed_ips', []);
    expect($account->fresh()->allowed_ips)->toBeNull();

    $change = collect($this->getJson('/api/v1/audit/activities?filter[log_name]=user')->json('data'))
        ->flatMap(fn (array $row) => $row['fields'] ?? [])->firstWhere('key', 'allowed_ips');
    expect($change['label'])->toBe('Addresses it works from');
});

it('lets no one widen where a stronger account works from', function () {
    $this->postJson('/api/v1/roles', ['name' => 'Settings keeper', 'permissions' => ['settings.update']])->assertCreated();
    $strong = ($this->account)(['allowed_ips' => ['203.0.113.7']], 'Settings keeper');
    Sanctum::actingAs(tenantUser($this->tenant, 'Administrator'));

    $this->patchJson("/api/v1/integrations/{$strong->public_id}", ['allowed_ips' => []])->assertUnprocessable();
    $this->patchJson("/api/v1/integrations/{$strong->public_id}", ['allowed_ips' => ['203.0.113.7']])->assertOk();
});

it('names the account as the one who acted, marked as an integration, with no e-mail', function () {
    $plain = ($this->token)(($this->account)());

    ($this->bearer)($plain)->postJson('/api/v1/cardholders', ['full_name' => 'Sent By Payroll'])->assertCreated();

    ($this->asOwner)();
    $this->getJson('/api/v1/audit/activities?filter[log_name]=cardholders')->assertOk()
        ->assertJsonPath('data.0.causer', ['kind' => 'integration', 'name' => 'Payroll', 'email' => null]);

    $row = $this->getJson('/api/v1/reports/audit-changes?filter[log_name]=cardholders&columns[]=audit.who&columns[]=audit.who_email')->assertOk()->json('data.0.values');
    expect($row['audit.who'])->toBe('Payroll (integration)')->and($row['audit.who_email'])->toBeNull();

    app()->setLocale('bn');
    expect($this->getJson('/api/v1/reports/audit-changes?filter[log_name]=cardholders&columns[]=audit.who', ['Accept-Language' => 'bn'])->json('data.0.values')['audit.who'])->toBe('Payroll (ইন্টিগ্রেশন)');
});

it('still shows a person as a person', function () {
    $this->postJson('/api/v1/cardholders', ['full_name' => 'By Hand'])->assertCreated();

    $this->getJson('/api/v1/audit/activities?filter[log_name]=cardholders')->assertJsonPath('data.0.causer.kind', 'user');
});

it('says a booking and its steps came through the API, by token only', function () {
    $this->travelTo(now()->parse('2026-10-16 03:00:00'));
    $this->tenant->forceFill(['timezone' => 'Asia/Dhaka', 'settings' => ['appointments' => ['enabled' => true, 'booking' => ['staff', 'public'], 'approval' => false]]])->save();
    $this->owner->unsetRelation('tenant');
    $place = ($this->inTenant)(fn () => AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'slot_capacity' => 5, 'day_capacity' => 5]));
    $holder = ($this->inTenant)(fn () => Cardholder::factory()->create(['tenant_id' => $this->tenant->id]));
    $booking = ['location' => $place->public_id, 'service' => 'new_card', 'cardholder' => $holder->public_id, 'date' => '2026-10-18'];
    $account = ($this->account)();
    $plain = ($this->token)($account);

    $byToken = ($this->bearer)($plain)->postJson('/api/v1/appointments', [...$booking, 'time' => '09:30'])->assertCreated()
        ->assertJsonPath('data.source.key', 'api')
        ->assertJsonPath('data.source.actor', 'Another system (API)')
        ->assertJsonPath('data.booked_by.name', 'Payroll')
        ->json('data.id');
    ($this->bearer)($plain)->postJson("/api/v1/appointments/{$byToken}/cancel", ['reason' => 'Left the company'])->assertOk()
        ->assertJsonPath('data.status_changed_via.key', 'api');
    ($this->bearer)($plain)->postJson("/api/v1/appointments/{$byToken}/status", ['status' => 'confirmed'])->assertOk()
        ->assertJsonPath('data.status_changed_via.key', 'api');

    ($this->asOwner)();
    $this->postJson("/api/v1/appointments/{$byToken}/cancel", [])->assertOk()->assertJsonPath('data.status_changed_via.key', 'staff');
    $this->postJson('/api/v1/appointments', [...$booking, 'time' => '10:30'])->assertCreated()->assertJsonPath('data.source.key', 'staff');
});
