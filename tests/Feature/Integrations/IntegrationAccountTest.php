<?php

use App\Enums\UserKind;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
| 6.1a: an organization's accounts for other systems. A user of its own kind: it holds one role, signs in with no
| password, and stays out of everything about people.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner('alpha', 'owner@example.com');
    Sanctum::actingAs($this->owner);
    $this->account = fn (array $attributes = [], string $role = 'Operator', ?Tenant $tenant = null): User => app(TenantContext::class)
        ->run($tenant ?? $this->tenant, fn () => User::factory()->integration()->create(['tenant_id' => ($tenant ?? $this->tenant)->id, ...$attributes])->assignRole($role));
});

it('makes an account with a role, no e-mail and no password anyone is told', function () {
    $response = $this->postJson('/api/v1/integrations', ['name' => 'Payroll', 'description' => 'Sends staff changes', 'role' => 'Operator'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Payroll')
        ->assertJsonPath('data.description', 'Sends staff changes')
        ->assertJsonPath('data.role', 'Operator')
        ->assertJsonPath('data.status', 'active');

    expect(array_keys($response->json('data')))->toEqual(['id', 'name', 'description', 'role', 'status', 'allowed_ips', 'tokens_count', 'last_used_at', 'created_at']);

    $account = User::where('public_id', $response->json('data.id'))->first();
    expect($account->kind)->toBe(UserKind::Integration)
        ->and($account->tenant_id)->toBe($this->tenant->id)
        ->and($account->email)->toBeNull()
        ->and($account->username)->toBeNull()
        ->and(strlen($account->password))->toBeGreaterThan(20)
        ->and($account->hasRole('Operator'))->toBeTrue();
});

it('never signs in, with the same answer as someone who does not exist', function () {
    $password = 'Known#Pass789';
    $account = ($this->account)(['email' => 'payroll@example.com', 'username' => 'payroll', 'password' => Hash::make($password)]);

    $unknown = $this->postJson('/api/v1/auth/login', ['organization' => 'alpha', 'login' => 'nobody@example.com', 'password' => $password])->assertStatus(422);
    foreach (['payroll@example.com', 'payroll'] as $login) {
        $answer = $this->postJson('/api/v1/auth/login', ['organization' => 'alpha', 'login' => $login, 'password' => $password])->assertStatus(422);
        expect($answer->json())->toBe($unknown->json());
    }

    $this->postJson('/api/v1/auth/forgot-password', ['organization' => 'alpha', 'email' => 'payroll@example.com']);
    expect(DB::table('password_reset_tokens')->where('user_id', $account->id)->count())->toBe(0);
});

it('stays out of everything about people, and the users API cannot reach it', function () {
    $account = ($this->account)(['name' => 'Payroll']);

    $this->getJson('/api/v1/users')->assertOk()->assertJsonMissing(['name' => 'Payroll']);
    $this->getJson('/api/v1/users?filter[search]=Payroll')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/reports/users')->assertOk()->assertJsonPath('meta.totals.users', 1);

    $role = collect($this->getJson('/api/v1/roles')->json('data'))->firstWhere('name', 'Operator');
    expect($role['users_count'])->toBe(0);
    $this->deleteJson("/api/v1/roles/{$role['id']}")->assertUnprocessable()->assertJsonPath('message', __('roles.in_use'));

    $url = "/api/v1/users/{$account->public_id}";
    $this->getJson($url)->assertNotFound();
    $this->putJson($url, ['name' => 'x', 'email' => 'x@example.com', 'status' => 'active', 'roles' => ['Owner'], 'password' => 'Taken#Over789', 'password_confirmation' => 'Taken#Over789'])->assertNotFound();
    $this->deleteJson($url)->assertNotFound();
    expect($account->fresh()->hasRole('Owner'))->toBeFalse();

    // And the other way round: a person is no integration
    $this->getJson("/api/v1/integrations/{$this->owner->public_id}")->assertNotFound();
    $this->deleteJson("/api/v1/integrations/{$this->owner->public_id}")->assertNotFound();
});

it('counts accounts apart from people for the platform', function () {
    ($this->account)();
    $admin = User::factory()->platformAdmin()->create();
    Sanctum::actingAs($admin);

    expect(collect($this->getJson('/api/v1/platform/tenants')->assertOk()->json('data'))->firstWhere('slug', 'alpha')['users_count'])->toBe(1);
});

it('keeps each organization to its own accounts and roles', function () {
    [$other] = tenantWithOwner('beta', 'b@example.com');
    $theirs = ($this->account)(['name' => 'Theirs'], 'Operator', $other);
    ($this->account)(['name' => 'Ours']);

    $this->getJson('/api/v1/integrations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Ours');
    $this->getJson("/api/v1/integrations/{$theirs->public_id}")->assertNotFound();
    $this->patchJson("/api/v1/integrations/{$theirs->public_id}", ['name' => 'Taken'])->assertNotFound();
    $this->deleteJson("/api/v1/integrations/{$theirs->public_id}")->assertNotFound();

    app(TenantContext::class)->run($other, fn () => Role::create(['name' => 'Only Theirs', 'guard_name' => 'web', 'tenant_id' => $other->id]));
    $this->postJson('/api/v1/integrations', ['name' => 'X', 'role' => 'Only Theirs'])->assertJsonValidationErrors('role');
});

it('follows the permission for each default role', function (string $role, bool $allowed) {
    $account = ($this->account)();
    Sanctum::actingAs(tenantUser($this->tenant, $role));
    $status = $allowed ? 200 : 403;

    $this->getJson('/api/v1/integrations')->assertStatus($status);
    $this->getJson("/api/v1/integrations/{$account->public_id}")->assertStatus($status);
    $this->postJson('/api/v1/integrations', ['name' => 'Payroll', 'role' => 'Viewer'])->assertStatus($allowed ? 201 : 403);
    $this->patchJson("/api/v1/integrations/{$account->public_id}", ['description' => 'x'])->assertStatus($status);
    $this->deleteJson("/api/v1/integrations/{$account->public_id}")->assertStatus($allowed ? 204 : 403);
})->with([['Administrator', true], ['Operator', false], ['Viewer', false]]);

it('asks to be signed in', function () {
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/integrations')->assertUnauthorized();
});

it('never gives an account the Owner role, or a role beyond the giver', function () {
    $this->postJson('/api/v1/integrations', ['name' => 'X', 'role' => 'Owner'])->assertJsonValidationErrors('role');
    $account = ($this->account)();
    $this->patchJson("/api/v1/integrations/{$account->public_id}", ['role' => 'Owner'])->assertJsonValidationErrors('role');

    $this->postJson('/api/v1/roles', ['name' => 'Settings keeper', 'permissions' => ['settings.update']])->assertCreated();
    $strong = ($this->account)(['name' => 'Strong'], 'Settings keeper');
    Sanctum::actingAs(tenantUser($this->tenant, 'Administrator'));

    $this->postJson('/api/v1/integrations', ['name' => 'Y', 'role' => 'Settings keeper'])
        ->assertUnprocessable()->assertJsonPath('message', __('roles.beyond_own'));
    $this->patchJson("/api/v1/integrations/{$account->public_id}", ['role' => 'Settings keeper'])->assertUnprocessable();

    // An account stronger than the actor: its words can be corrected, nothing else
    $url = "/api/v1/integrations/{$strong->public_id}";
    $this->patchJson($url, ['description' => 'Corrected'])->assertOk();
    $this->patchJson($url, ['role' => 'Settings keeper'])->assertOk();
    $this->patchJson($url, ['status' => 'inactive'])->assertUnprocessable();
    $this->patchJson($url, ['role' => 'Viewer'])->assertUnprocessable();
    $this->deleteJson($url)->assertUnprocessable();
    expect($strong->fresh()->status->value)->toBe('active');
});

it('tells the page which roles this person can give, and how long a token lasts', function () {
    $this->postJson('/api/v1/roles', ['name' => 'Settings keeper', 'permissions' => ['settings.update']])->assertCreated();

    expect($this->getJson('/api/v1/integrations')->assertOk()->json('meta'))->toMatchArray(['token_days' => 365, 'max_token_days' => 1095])
        ->and($this->getJson('/api/v1/integrations')->json('meta.assignable_roles'))->toContain('Settings keeper', 'Operator')->not->toContain('Owner')
        ->and($this->getJson('/api/v1/users')->json('meta.assignable_roles'))->toContain('Owner', 'Settings keeper');

    Sanctum::actingAs(tenantUser($this->tenant, 'Administrator'));
    expect($this->getJson('/api/v1/integrations')->json('meta.assignable_roles'))->toEqualCanonicalizing(['Administrator', 'Operator', 'Viewer'])
        ->and($this->getJson('/api/v1/users')->json('meta.assignable_roles'))->toEqualCanonicalizing(['Administrator', 'Operator', 'Viewer']);
});

it('takes back its own answer, and a change leaves what is not sent', function () {
    $account = ($this->account)(['name' => 'Payroll', 'description' => 'Sends staff changes']);
    $url = "/api/v1/integrations/{$account->public_id}";

    $answer = $this->getJson($url)->assertOk()->json('data');
    $this->putJson($url, $answer)->assertOk()->assertJsonPath('data', $answer);

    $this->patchJson($url, ['status' => 'inactive'])->assertOk()
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.name', 'Payroll')
        ->assertJsonPath('data.description', 'Sends staff changes')
        ->assertJsonPath('data.role', 'Operator');
    $this->patchJson($url, ['description' => null])->assertOk()->assertJsonPath('data.description', null);
    $this->patchJson($url, ['role' => 'Viewer'])->assertOk()->assertJsonPath('data.role', 'Viewer');
    expect($account->fresh()->getRoleNames()->all())->toBe(['Viewer']);
});

it('keeps names apart, and frees a name when its account is deleted', function () {
    $account = ($this->account)(['name' => 'Payroll']);

    $this->postJson('/api/v1/integrations', ['name' => 'Payroll', 'role' => 'Viewer'])->assertJsonValidationErrors('name');
    $this->patchJson("/api/v1/integrations/{$account->public_id}", ['name' => 'Payroll'])->assertOk();

    // A person of that name is no clash
    tenantUser($this->tenant, 'Viewer', ['name' => 'Timesheets']);
    $this->postJson('/api/v1/integrations', ['name' => 'Timesheets', 'role' => 'Viewer'])->assertCreated();

    $this->deleteJson("/api/v1/integrations/{$account->public_id}")->assertNoContent();
    $this->postJson('/api/v1/integrations', ['name' => 'Payroll', 'role' => 'Viewer'])->assertCreated();
});

it('filters and sorts the list', function () {
    ($this->account)(['name' => 'Beta sync', 'description' => 'Payroll feed']);
    ($this->account)(['name' => 'Alpha sync', 'status' => 'inactive'], 'Viewer');

    $this->getJson('/api/v1/integrations')->assertJsonPath('data.0.name', 'Alpha sync');
    $this->getJson('/api/v1/integrations?sort=-name')->assertJsonPath('data.0.name', 'Beta sync');
    $this->getJson('/api/v1/integrations?filter[search]=payroll')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Beta sync');
    $this->getJson('/api/v1/integrations?filter[status]=inactive')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Alpha sync');
    $this->getJson('/api/v1/integrations?filter[role]=Viewer')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Alpha sync');
    $this->getJson('/api/v1/integrations?filter[kind]=person')->assertJsonValidationErrors('filter');
    $this->getJson('/api/v1/integrations?sort=password')->assertJsonValidationErrors('sort');
});

it('deletes for good only the account\'s access: its tokens go, the record and the audit trail stay', function () {
    $account = ($this->account)(['name' => 'Payroll']);
    $account->createToken('main');

    Sanctum::actingAs($account);
    $this->postJson('/api/v1/cardholders', ['full_name' => 'Sent By Payroll'])->assertCreated();

    Sanctum::actingAs($this->owner);
    $this->deleteJson("/api/v1/integrations/{$account->public_id}")->assertNoContent();

    expect($account->tokens()->count())->toBe(0)
        ->and(User::withTrashed()->find($account->id)->trashed())->toBeTrue();
    $this->getJson('/api/v1/integrations')->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/integrations/{$account->public_id}")->assertNotFound();
    $this->getJson('/api/v1/audit/activities?filter[log_name]=cardholders')->assertJsonPath('data.0.causer.name', 'Payroll');
});

it('stops a turned-off account until it is turned on again', function () {
    $account = ($this->account)(['status' => 'inactive']);

    Sanctum::actingAs($account);
    $this->getJson('/api/v1/cardholders')->assertForbidden();

    Sanctum::actingAs($this->owner);
    $this->patchJson("/api/v1/integrations/{$account->public_id}", ['status' => 'active'])->assertOk();

    Sanctum::actingAs($account->fresh());
    $this->getJson('/api/v1/cardholders')->assertOk();
});

it('keeps what only a person does away from an account, whatever its role', function () {
    $this->postJson('/api/v1/roles', ['name' => 'Wide', 'permissions' => ['integrations.manage', 'users.view']])->assertCreated();
    Sanctum::actingAs(($this->account)([], 'Wide')->fresh());

    $this->getJson('/api/v1/users')->assertOk();
    $this->getJson('/api/v1/integrations')->assertForbidden();
    $this->postJson('/api/v1/integrations', ['name' => 'Another', 'role' => 'Wide'])->assertForbidden();
    $this->postJson('/api/v1/auth/tokens', ['name' => 'More'])->assertForbidden()->assertJsonPath('message', __('integrations.person_only'));
    $this->getJson('/api/v1/auth/tokens')->assertForbidden();
    $this->putJson('/api/v1/auth/password', ['current_password' => 'x', 'password' => 'Taken#Over789', 'password_confirmation' => 'Taken#Over789'])->assertForbidden();
    $this->postJson('/api/v1/auth/two-factor')->assertForbidden();
    $this->putJson('/api/v1/auth/me', ['name' => 'Renamed'])->assertForbidden();
    $this->getJson('/api/v1/auth/me')->assertOk();
});

it('keeps every change in the audit log, named for people', function () {
    $id = $this->postJson('/api/v1/integrations', ['name' => 'Payroll', 'description' => 'Sends staff changes', 'role' => 'Operator'])->json('data.id');
    $this->patchJson("/api/v1/integrations/{$id}", ['name' => 'Payroll v2', 'role' => 'Viewer', 'status' => 'inactive'])->assertOk();
    $this->deleteJson("/api/v1/integrations/{$id}")->assertNoContent();

    $rows = $this->getJson('/api/v1/audit/activities?filter[log_name]=user')->assertOk()->json('data');
    $fields = collect($rows)->reverse()->flatMap(fn (array $row) => $row['fields'])->groupBy('key');

    expect($fields['kind'][0])->toMatchArray(['label' => 'Account type', 'new' => 'Integration'])
        ->and($fields['description'][0]['new'])->toBe('Sends staff changes')
        ->and($fields['roles']->pluck('new')->all())->toBe(['Operator', 'Viewer'])
        ->and($fields['roles'][1]['old'])->toBe('Operator')
        ->and(collect($rows)->pluck('event')->unique()->sort()->values()->all())->toBe(['created', 'deleted', 'updated'])
        ->and(json_encode($rows))->not->toContain('password');
    expect(Activity::query()->where('log_name', 'user')->where('causer_id', $this->owner->id)->count())->toBeGreaterThanOrEqual(4);
});
