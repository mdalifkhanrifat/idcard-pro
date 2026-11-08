<?php

use App\Models\Cardholder;
use App\Models\CardholderSync;
use App\Models\IdempotencyKey;
use App\Models\User;
use App\Services\Cardholders\CardholderApiSyncService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Exceptions;
use Spatie\Permission\Models\Role;

/*
| 6.3c: Idempotency-Key. A system that sends the same request again (it timed out, it does not know whether it worked)
| gets the first answer back, and the work is done once. Real bearer tokens of integration accounts.
*/

beforeEach(function () {
    [$this->tenant] = tenantWithOwner();
    $this->account = fn (string $name): User => app(TenantContext::class)->run($this->tenant, function () use ($name): User {
        Role::findOrCreate('HR sync', 'web')->givePermissionTo('cardholders.sync');

        return User::factory()->integration()->create(['tenant_id' => $this->tenant->id, 'name' => $name])->assignRole('HR sync');
    });
    $this->hr = ($this->account)('Payroll');
    $this->tokenOf = fn (User $account): string => $account->createToken('Main', ['*'], now()->addYear())->plainTextToken;
    $this->token = ($this->tokenOf)($this->hr);
    $this->send = function (array $body, ?string $key = 'key-1', ?string $token = null, array $headers = []) {
        app('auth')->forgetGuards();
        app(TenantContext::class)->set(null);

        return $this->withToken($token ?? $this->token)->postJson('/api/v1/sync/cardholders', $body, array_filter(['Idempotency-Key' => $key, ...$headers]));
    };
    $this->body = ['records' => [['employee_no' => 'E-1', 'full_name' => 'Rahim Uddin']]];
    $this->people = fn (): int => Cardholder::query()->withoutGlobalScopes()->count();
});

it('does the work once and answers a second sending with the first answer', function () {
    $first = ($this->send)($this->body)->assertOk()->assertJsonPath('data.records.0.result', 'created');
    expect($first->headers->has('Idempotent-Replayed'))->toBeFalse();

    $again = ($this->send)($this->body)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json())->toBe($first->json())
        ->and($again->headers->get('Content-Type'))->toContain('application/json')
        ->and(($this->people)())->toBe(1)
        ->and(CardholderSync::query()->withoutGlobalScopes()->count())->toBe(1);

    // Without the key the request is just a request: the same person again, left as they are
    ($this->send)($this->body, null)->assertOk()->assertJsonPath('data.records.0.result', 'unchanged');
    expect(IdempotencyKey::query()->count())->toBe(1);
});

it('refuses the same key for another request, and keeps keys apart by account', function () {
    ($this->send)($this->body)->assertOk();

    ($this->send)(['records' => [['employee_no' => 'E-2', 'full_name' => 'Karim']]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.idempotency_key.0', __('integrations.idempotency.mismatch'));
    expect(($this->people)())->toBe(1);

    // Another system's key-1 is its own
    ($this->send)(['records' => [['employee_no' => 'E-2', 'full_name' => 'Karim']]], 'key-1', ($this->tokenOf)(($this->account)('Pension')))
        ->assertOk()
        ->assertJsonPath('data.records.0.result', 'created');
    expect(($this->people)())->toBe(2);
});

it('says a request is still being worked on, and takes over a key whose request died', function () {
    // The first sending, caught while it is still being worked on: no answer yet, and nobody saved
    ($this->send)($this->body)->assertOk();
    $held = IdempotencyKey::query()->sole();
    $held->forceFill(['status_code' => null, 'response_body' => null])->save();
    Cardholder::query()->withoutGlobalScopes()->delete();

    ($this->send)($this->body)->assertConflict()->assertJsonPath('message', __('integrations.idempotency.in_progress'));
    expect(($this->people)())->toBe(0);

    $this->travel(11)->minutes();
    ($this->send)($this->body)->assertOk()->assertJsonPath('data.records.0.result', 'created');
    expect($held->fresh()->status_code)->toBe(200);
});

it('treats an expired key as new', function () {
    ($this->send)($this->body)->assertOk();
    $this->travel(25)->hours();

    ($this->send)(['records' => [['employee_no' => 'E-2', 'full_name' => 'Karim']]])->assertOk()->assertJsonPath('data.records.0.result', 'created');

    expect(($this->people)())->toBe(2)
        ->and(IdempotencyKey::query()->count())->toBe(1);
});

it('keeps a refusal, but lets a request that failed on the server be tried again', function () {
    // The first run breaks on the server, the next one works
    Exceptions::fake();
    $real = app(CardholderApiSyncService::class);
    $runs = 0;
    $this->mock(CardholderApiSyncService::class, fn ($mock) => $mock->shouldReceive('run')->andReturnUsing(function (...$arguments) use ($real, &$runs) {
        if ($runs++ === 0) {
            throw new RuntimeException('Database gone');
        }

        return $real->run(...$arguments);
    }));

    ($this->send)($this->body)->assertServerError();
    expect(IdempotencyKey::query()->where('key', 'key-1')->exists())->toBeFalse();
    ($this->send)($this->body)->assertOk()->assertJsonPath('data.records.0.result', 'created');

    ($this->send)(['records' => []], 'empty')->assertUnprocessable();
    ($this->send)(['records' => []], 'empty')->assertUnprocessable()->assertHeader('Idempotent-Replayed', 'true');
});

it('refuses a key that is not one, in the reader\'s language', function () {
    ($this->send)($this->body, str_repeat('x', 256))->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    ($this->send)($this->body, 'has space', null, ['Accept-Language' => 'bn'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Idempotency-Key হবে ১ থেকে ২৫৫টা দৃশ্যমান অক্ষর (অক্ষর, অঙ্ক, চিহ্ন), যেমন একটা UUID।');

    expect(($this->people)())->toBe(0)
        ->and(IdempotencyKey::query()->count())->toBe(0);
});

it('forgets kept answers once they expire', function () {
    ($this->send)($this->body)->assertOk();
    ($this->send)($this->body, 'key-2')->assertOk();
    $this->travel(25)->hours();
    ($this->send)($this->body, 'key-3')->assertOk();

    $this->artisan('model:prune', ['--model' => [IdempotencyKey::class]])->assertSuccessful();

    expect(IdempotencyKey::query()->pluck('key')->all())->toBe(['key-3']);
});
