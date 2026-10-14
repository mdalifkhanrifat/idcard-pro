<?php

use App\Models\NotificationMessage;
use App\Models\Tenant;
use App\Repositories\Contracts\NotificationMessageRepositoryInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    [$this->acme, $this->acmeOwner] = tenantWithOwner();
    [$this->beta, $this->betaOwner] = tenantWithOwner('beta', 'owner@beta.test');
    // Messages of each organization, logged 200, 100, 80 and 1 days ago
    $this->logged = function (Tenant $tenant): void {
        foreach ([200, 100, 80, 1] as $days) {
            NotificationMessage::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays($days), 'body' => "{$days} days ago"]);
        }
    };
    ($this->logged)($this->acme);
    ($this->logged)($this->beta);
    $this->left = fn (Tenant $tenant) => NotificationMessage::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->pluck('body')->all();
    $this->choose = fn (Tenant $tenant, array $notifications) => $tenant->forceFill(['settings' => [...($tenant->settings ?? []), 'notifications' => $notifications]])->save();
});

it('keeps every log for good unless the organization chose to have old messages deleted', function () {
    // Days chosen but deleting not turned on: nothing goes
    ($this->choose)($this->acme, ['log_days' => 30]);

    $this->artisan('app:prune-notification-log')
        ->expectsOutputToContain('No organization has old messages deleted; every log is kept.')
        ->assertSuccessful();

    expect(($this->left)($this->acme))->toHaveCount(4)
        ->and(($this->left)($this->beta))->toHaveCount(4)
        ->and(Activity::query()->where('log_name', 'notifications')->count())->toBe(0);
});

it('deletes only an organization\'s messages older than its own days, and notes it in its audit log as done by the system', function () {
    ($this->choose)($this->acme, ['prune_log' => true, 'log_days' => 90]);

    $this->artisan('app:prune-notification-log')->expectsOutputToContain('acme: 2 old messages deleted.')->assertSuccessful();

    expect(($this->left)($this->acme))->toBe(['80 days ago', '1 days ago'])
        // Another organization's log is its own business
        ->and(($this->left)($this->beta))->toHaveCount(4);

    $entry = Activity::query()->where('log_name', 'notifications')->sole();
    expect($entry->event)->toBe('pruned')
        ->and($entry->causer_id)->toBeNull()
        ->and($entry->subject_id)->toBe($this->acme->id)
        ->and($entry->properties->all())->toBe(['messages' => 2, 'days' => 90]);

    // Shown on the organization's audit page (by the system), never on another's
    Sanctum::actingAs($this->acmeOwner);
    $audit = $this->getJson('/api/v1/audit/activities?filter[log_name]=notifications')->assertOk();
    expect($audit->json('data'))->toHaveCount(1)
        ->and($audit->json('data.0'))->toMatchArray(['event' => 'pruned', 'causer' => null, 'changes' => ['messages' => 2, 'days' => 90]])
        ->and($audit->json('meta.log_names'))->toContain('notifications');
    Sanctum::actingAs($this->betaOwner);
    expect($this->getJson('/api/v1/audit/activities?filter[log_name]=notifications')->json('data'))->toBe([]);

    // A second run finds nothing more and notes nothing
    $this->artisan('app:prune-notification-log acme')->expectsOutputToContain('acme: 0 old messages deleted.')->assertSuccessful();
    expect(Activity::query()->where('log_name', 'notifications')->count())->toBe(1);
});

it('shows on the audit page what the system changed in the organization\'s settings, with no one signed in', function () {
    // E.g. from the server's console: no user acting
    $this->acme->update(['settings' => [...($this->acme->settings ?? []), 'card_number_on_reissue' => 'new']]);

    Sanctum::actingAs($this->acmeOwner);
    $entry = collect($this->getJson('/api/v1/audit/activities?filter[log_name]=tenant')->json('data'))->first(fn (array $row) => $row['causer'] === null);
    expect($entry['changes']['attributes'])->toMatchArray(['settings.card_number_on_reissue' => 'new']);
    // The other organization sees its own creation by the system, nothing of this one
    Sanctum::actingAs($this->betaOwner);
    $betas = collect($this->getJson('/api/v1/audit/activities?filter[log_name]=tenant')->json('data'));
    expect($betas->pluck('event')->all())->toBe(['created'])
        ->and($betas->first()['changes']['attributes']['slug'])->toBe('beta');
});

it('deletes a large log in chunks and runs every day', function () {
    NotificationMessage::factory()->count(5)->create(['tenant_id' => $this->acme->id, 'created_at' => now()->subDays(400)]);

    expect(app(NotificationMessageRepositoryInterface::class)->deleteOlderThan($this->acme->id, Date::now()->subDays(90), 2))->toBe(7)
        ->and(($this->left)($this->acme))->toBe(['80 days ago', '1 days ago']);

    $this->artisan('app:prune-notification-log nowhere')->expectsOutputToContain('No such organization.')->assertFailed();
    $scheduled = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'app:prune-notification-log'));
    expect($scheduled)->not->toBeNull()
        ->and($scheduled->expression)->toBe('0 0 * * *');
});
