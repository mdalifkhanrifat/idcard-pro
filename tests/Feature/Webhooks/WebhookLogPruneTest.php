<?php

use App\Enums\WebhookDeliveryStatus;
use App\Models\Tenant;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/*
| 6.2b-4: the log of deliveries is kept for good unless an organization chooses to have old ones deleted, after its own
| number of days, and each deletion is noted in its audit log.
*/

beforeEach(function () {
    [$this->acme, $this->owner] = tenantWithOwner();
    [$this->beta] = tenantWithOwner('beta', 'owner@beta.test');
    $this->logged = function (Tenant $tenant): Webhook {
        $webhook = app(TenantContext::class)->run($tenant, fn () => Webhook::factory()->create(['tenant_id' => $tenant->id]));
        foreach ([200, 100, 80, 1] as $days) {
            WebhookDelivery::factory()->create(['tenant_id' => $tenant->id, 'webhook_id' => $webhook->id, 'created_at' => now()->subDays($days), 'event' => "{$days}.days"]);
        }

        return $webhook;
    };
    ($this->logged)($this->acme);
    ($this->logged)($this->beta);
    $this->left = fn (Tenant $tenant) => WebhookDelivery::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->pluck('event')->all();
    $this->choose = fn (Tenant $tenant, array $webhooks) => $tenant->forceFill(['settings' => [...($tenant->settings ?? []), 'webhooks' => $webhooks]])->save();
});

it('keeps every log for good unless the organization chose otherwise', function () {
    ($this->choose)($this->acme, ['log_days' => 30]);

    $this->artisan('app:prune-webhook-deliveries')
        ->expectsOutputToContain('No organization has old deliveries deleted; every log is kept.')
        ->assertSuccessful();

    expect(($this->left)($this->acme))->toHaveCount(4)
        ->and(Activity::query()->where('log_name', 'webhooks')->where('event', 'pruned')->count())->toBe(0);
});

it('deletes only this organization\'s deliveries older than its own days, and notes it as done by the system', function () {
    ($this->choose)($this->acme, ['prune_log' => true, 'log_days' => 90]);

    $this->artisan('app:prune-webhook-deliveries')->expectsOutputToContain('acme: 2 old deliveries deleted.')->assertSuccessful();

    expect(($this->left)($this->acme))->toBe(['80.days', '1.days'])
        ->and(($this->left)($this->beta))->toHaveCount(4);

    $entry = Activity::query()->where('log_name', 'webhooks')->where('event', 'pruned')->sole();
    expect($entry->causer_id)->toBeNull()
        ->and($entry->subject_id)->toBe($this->acme->id)
        ->and($entry->properties->all())->toBe(['deliveries' => 2, 'days' => 90]);

    Sanctum::actingAs($this->owner);
    $audit = $this->getJson('/api/v1/audit/activities?filter[log_name]=webhooks')->assertOk();
    expect(collect($audit->json('data'))->firstWhere('event', 'pruned'))->toMatchArray(['causer' => null, 'changes' => ['deliveries' => 2, 'days' => 90]]);
});

it('takes one organization by name, and refuses one it does not know', function () {
    ($this->choose)($this->acme, ['prune_log' => true, 'log_days' => 90]);
    ($this->choose)($this->beta, ['prune_log' => true, 'log_days' => 90]);

    $this->artisan('app:prune-webhook-deliveries', ['tenant' => 'beta'])->assertSuccessful();
    expect(($this->left)($this->beta))->toHaveCount(2)
        ->and(($this->left)($this->acme))->toHaveCount(4);

    $this->artisan('app:prune-webhook-deliveries', ['tenant' => 'nobody'])->assertFailed();
});

it('keeps a delivery that sent an old one again, no longer linked', function () {
    ($this->choose)($this->acme, ['prune_log' => true, 'log_days' => 90]);
    $old = WebhookDelivery::query()->withoutGlobalScopes()->where('tenant_id', $this->acme->id)->where('event', '200.days')->sole();
    $new = WebhookDelivery::query()->withoutGlobalScopes()->where('tenant_id', $this->acme->id)->where('event', '1.days')->sole();
    $new->update(['resend_of_id' => $old->id, 'status' => WebhookDeliveryStatus::Queued]);

    $this->artisan('app:prune-webhook-deliveries')->assertSuccessful();

    expect($new->fresh()->resend_of_id)->toBeNull();
});

it('runs every day', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode(' ');

    expect($commands)->toContain('app:prune-webhook-deliveries');
});

it('is set through the organization settings, off by default, unsent parts left alone, and noted', function () {
    Sanctum::actingAs($this->owner);

    $answer = $this->getJson('/api/v1/settings')->assertOk();
    expect($answer->json('data.webhooks'))->toBe(['prune_log' => false, 'log_days' => 365])
        ->and($answer->json('data.webhook_options.log_days'))->toBe(['min' => 7, 'max' => 3650]);

    $save = fn (array $webhooks) => $this->postJson('/api/v1/settings', ['name' => 'Acme', 'timezone' => 'Asia/Dhaka', 'locale' => 'en', 'primary_color' => '#112233', 'webhooks' => $webhooks]);

    $save(['prune_log' => true])->assertOk()->assertJsonPath('data.webhooks', ['prune_log' => true, 'log_days' => 365]);
    $save(['log_days' => 90])->assertOk()->assertJsonPath('data.webhooks', ['prune_log' => true, 'log_days' => 90]);
    $save(['log_days' => 3])->assertUnprocessable()->assertJsonValidationErrors('webhooks.log_days');
    $save(['other' => 1])->assertUnprocessable();
    $save($this->getJson('/api/v1/settings')->json('data.webhooks'))->assertOk()->assertJsonPath('data.webhooks', ['prune_log' => true, 'log_days' => 90]);

    $changes = Activity::query()->where('log_name', 'tenant')->orWhere('subject_type', $this->acme->getMorphClass())->latest('id')->get()
        ->flatMap(fn (Activity $activity) => array_keys((array) ($activity->attribute_changes['attributes'] ?? [])));
    expect($changes)->toContain('settings.webhooks.prune_log', 'settings.webhooks.log_days');
});
