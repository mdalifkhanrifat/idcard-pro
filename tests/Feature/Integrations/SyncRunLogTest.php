<?php

use App\Enums\CardholderSyncKind;
use App\Models\CardholderSync;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
| 6.3g-1: the HR sync's run log (whoever manages integrations sees every run, an HR system's account its own),
| the fields a record may hold, and deleting old runs only where the organization chose it.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    [$this->hr, $this->payroll] = app(TenantContext::class)->run($this->tenant, function (): array {
        Role::create(['name' => 'HR sync', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id])->givePermissionTo('cardholders.sync');

        return [
            User::factory()->integration()->create(['tenant_id' => $this->tenant->id, 'name' => 'HR'])->assignRole('HR sync'),
            User::factory()->integration()->create(['tenant_id' => $this->tenant->id, 'name' => 'Payroll'])->assignRole('HR sync'),
        ];
    });
    $this->run = fn (User $by, array $attributes = []) => CardholderSync::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $by->id, ...$attributes]);
    $this->as = function (User $user) {
        app('auth')->forgetGuards();
        app(TenantContext::class)->set(null);
        Sanctum::actingAs($user);
    };
});

it('lists every run, newest first, to whoever manages integrations, and each account its own', function () {
    $old = ($this->run)($this->hr, ['created_at' => now()->subDays(2), 'started_at' => now()->subDays(2)]);
    $leavers = ($this->run)($this->payroll, ['kind' => CardholderSyncKind::Deactivate, 'not_found_count' => 2, 'finished_at' => null]);

    ($this->as)($this->owner);
    $this->getJson('/api/v1/sync/cardholders/runs')
        ->assertOk()
        ->assertJsonPath('data.0.id', $leavers->public_id)
        ->assertJsonPath('data.0.kind', 'deactivate')
        ->assertJsonPath('data.0.account', ['id' => $this->payroll->public_id, 'name' => 'Payroll', 'kind' => 'integration', 'deleted' => false])
        ->assertJsonPath('data.0.not_found', 2)
        ->assertJsonPath('data.0.finished_at', null)
        ->assertJsonPath('data.1.id', $old->public_id)
        ->assertJsonPath('meta.kinds', ['upsert', 'deactivate']);
    $this->getJson('/api/v1/sync/cardholders/runs?filter[kind]=upsert')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->public_id);
    $this->getJson('/api/v1/sync/cardholders/runs?filter[kind]=all')->assertUnprocessable();

    // An HR system's account sees its own runs only
    ($this->as)($this->hr);
    $this->getJson('/api/v1/sync/cardholders/runs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->public_id);

    // Neither right: nothing
    ($this->as)(app(TenantContext::class)->run($this->tenant, fn () => tenantUser($this->tenant, 'Viewer')));
    $this->getJson('/api/v1/sync/cardholders/runs')->assertForbidden();
});

it('names every field a record may hold, in the reader\'s language, with the organization\'s HR names', function () {
    $this->tenant->forceFill(['settings' => ['integrations' => ['sync_fields' => ['emp_id' => 'employee_no']]]])->save();

    ($this->as)($this->hr);
    $answer = $this->getJson('/api/v1/sync/cardholders/fields')->assertOk()->assertJsonPath('meta.sync_fields', ['emp_id' => 'employee_no']);
    $fields = collect($answer->json('data'))->pluck('label', 'field');
    expect($fields->all())
        ->toMatchArray(['employee_no' => 'Employee no', 'rank' => 'Rank / grade', 'family' => 'Family', 'previous_office' => 'Previous office', 'status' => 'Record status'])
        ->and($fields->filter(fn (string $label, string $field) => $label === $field)->all())->toBe([]);

    $this->getJson('/api/v1/sync/cardholders/fields', ['Accept-Language' => 'bn'])->assertOk()->assertJsonPath('data.0.label', 'পূর্ণ নাম');

    ($this->as)(app(TenantContext::class)->run($this->tenant, fn () => tenantUser($this->tenant, 'Viewer')));
    $this->getJson('/api/v1/sync/cardholders/fields')->assertForbidden();
});

it('keeps every run unless the organization chose to delete old ones, and notes what it deleted', function () {
    $old = ($this->run)($this->hr, ['created_at' => now()->subDays(40)]);
    $recent = ($this->run)($this->hr, ['created_at' => now()->subDays(10)]);
    [$other] = tenantWithOwner('beta', 'owner@beta.test');
    $elsewhere = CardholderSync::factory()->create(['tenant_id' => $other->id, 'created_at' => now()->subDays(400)]);

    $this->artisan('app:prune-sync-runs')->expectsOutputToContain('every log is kept')->assertSuccessful();
    expect(CardholderSync::withoutGlobalScopes()->count())->toBe(3);

    // Chosen on the organization settings, as any setting
    ($this->as)($this->owner);
    $save = fn (array $integrations) => $this->postJson('/api/v1/settings', ['name' => 'Acme', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233', 'integrations' => $integrations]);
    $save(['sync_prune_log' => true, 'sync_log_days' => 3])->assertUnprocessable()->assertJsonValidationErrors('integrations.sync_log_days');
    $save(['sync_prune_log' => true, 'sync_log_days' => 30])->assertOk()
        ->assertJsonPath('data.integrations.sync_prune_log', true)
        ->assertJsonPath('data.integrations.sync_log_days', 30);

    $this->artisan('app:prune-sync-runs')->expectsOutputToContain('acme: 1 old runs deleted.')->assertSuccessful();
    expect(CardholderSync::withoutGlobalScopes()->pluck('id')->all())->toEqualCanonicalizing([$recent->id, $elsewhere->id])
        ->and($old->fresh())->toBeNull();

    $noted = Activity::query()->where('log_name', 'hr_sync')->sole();
    expect([$noted->event, $noted->causer_id, $noted->subject_id, $noted->properties->all()])->toBe(['pruned', null, $this->tenant->id, ['runs' => 1, 'days' => 30]]);

    // Nothing more to delete: nothing noted
    $this->artisan('app:prune-sync-runs', ['tenant' => 'acme'])->assertSuccessful();
    expect(Activity::query()->where('log_name', 'hr_sync')->count())->toBe(1);
});

it('reads and changes the HR sync settings on their own, as any organization setting', function () {
    ($this->as)($this->owner);
    $this->getJson('/api/v1/sync/cardholders/settings')->assertOk()
        ->assertExactJson(['data' => ['sync_fields' => [], 'sync_prune_log' => false, 'sync_log_days' => 365], 'meta' => ['log_days' => ['min' => 7, 'max' => 3650], 'can_update' => true]]);

    $this->putJson('/api/v1/sync/cardholders/settings', ['sync_fields' => ['emp_id' => 'employee_no']])->assertOk()
        ->assertJsonPath('data.sync_fields', ['emp_id' => 'employee_no'])
        ->assertJsonPath('data.sync_prune_log', false);
    // What is left out stays
    $this->putJson('/api/v1/sync/cardholders/settings', ['sync_prune_log' => true, 'sync_log_days' => 90])->assertOk()
        ->assertJsonPath('data', ['sync_fields' => ['emp_id' => 'employee_no'], 'sync_prune_log' => true, 'sync_log_days' => 90]);
    expect($this->tenant->fresh()->integrationSettings()['requests_per_minute'])->toBe((int) config('integrations.requests.per_minute'));

    $this->putJson('/api/v1/sync/cardholders/settings', ['sync_fields' => ['a' => 'rank', 'b' => 'rank']])->assertUnprocessable()
        ->assertJsonPath('errors.sync_fields.0', 'Only one HR field name can stand for “rank” (a, b).');
    $this->putJson('/api/v1/sync/cardholders/settings', ['sync_log_days' => 1], ['Accept-Language' => 'bn'])->assertUnprocessable()->assertJsonValidationErrors('sync_log_days');

    $noted = Activity::query()->where('log_name', 'tenant')->latest('id')->firstOrFail();
    expect($noted->causer_id)->toBe($this->owner->id)
        ->and(array_keys($noted->attribute_changes['attributes']))->toEqualCanonicalizing(['settings.integrations.sync_prune_log', 'settings.integrations.sync_log_days']);

    // Who manages integrations but not the settings reads them, and may not change them
    $manager = app(TenantContext::class)->run($this->tenant, function (): User {
        Role::create(['name' => 'Integrations only', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id])->givePermissionTo('integrations.manage');

        return tenantUser($this->tenant, 'Viewer')->syncRoles('Integrations only');
    });
    ($this->as)($manager);
    $this->getJson('/api/v1/sync/cardholders/settings')->assertOk()->assertJsonPath('meta.can_update', false);
    $this->putJson('/api/v1/sync/cardholders/settings', ['sync_prune_log' => false])->assertForbidden();
    ($this->as)($this->hr);
    $this->getJson('/api/v1/sync/cardholders/settings')->assertForbidden();
});
