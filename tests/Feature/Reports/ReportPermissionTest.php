<?php

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
| Who sees and downloads which report: reports.view opens the reports at all, reports.export downloads them,
| and each report also needs the permission of the list it shows (cards.view for card numbers...).
*/

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->owner->setRelation('tenant', $this->tenant);
    $this->roleWith = function (array $permissions): User {
        $name = 'Custom '.count(Role::all());
        app(TenantContext::class)->run($this->tenant, fn () => Role::create(['name' => $name, 'guard_name' => 'web', 'tenant_id' => $this->tenant->id])->givePermissionTo($permissions));

        return tenantUser($this->tenant, $name);
    };
    $this->slug = fn (string $key): string => str_replace('_', '-', $key);
    // A download's status; the file it wrote is removed (deleted only once actually sent)
    $this->downloadStatus = function (string $url): int {
        $response = $this->get($url)->baseResponse;
        if ($response instanceof BinaryFileResponse) {
            unlink($response->getFile()->getPathname());
        }

        return $response->getStatusCode();
    };
    // What the user may do with every report in the registry: [report key => [listed, opens, downloads]]
    $this->access = function (User $user): array {
        Sanctum::actingAs($user);
        $listed = collect($this->getJson('/api/v1/reports')->json('data') ?? [])->pluck('key')->all();

        return collect(array_keys(config('reports.reports')))->mapWithKeys(fn (string $key) => [$key => [
            in_array($key, $listed, true),
            $this->getJson('/api/v1/reports/'.($this->slug)($key))->status(),
            ($this->downloadStatus)('/api/v1/reports/'.($this->slug)($key).'/export?format=csv'),
        ]])->all();
    };
    $this->only = fn (array $keys, array $allowed, array $denied): array => collect(array_keys(config('reports.reports')))
        ->mapWithKeys(fn (string $key) => [$key => in_array($key, $keys, true) ? $allowed : $denied])->all();
});

it('gives the Owner and the Administrator every report, to see and to download', function (string $role) {
    $user = $role === 'Owner' ? $this->owner : tenantUser($this->tenant, $role);

    expect(($this->access)($user))->toBe(($this->only)(array_keys(config('reports.reports')), [true, 200, 200], []));
})->with(['Owner', 'Administrator']);

it('lets the Viewer see the reports of the lists it sees, without downloading any', function () {
    $seen = ['card_prints', 'expired_cards', 'cardholders', 'cards', 'family_members', 'qualifications', 'users', 'master_data', 'audit_changes', 'audit_logins'];

    expect(($this->access)(tenantUser($this->tenant, 'Viewer')))->toBe(($this->only)($seen, [true, 200, 403], [false, 403, 403]));
});

it('gives the Operator no report, as it has no reports.view', function () {
    $user = tenantUser($this->tenant, 'Operator');
    Sanctum::actingAs($user);
    $this->getJson('/api/v1/reports')->assertForbidden();

    expect(($this->access)($user))->toBe(($this->only)([], [], [false, 403, 403]));
});

it('opens no report with reports.view alone, as each report also needs its list\'s permission', function () {
    expect(($this->access)(($this->roleWith)(['reports.view', 'reports.export'])))->toBe(($this->only)([], [], [false, 403, 403]));
});

it('opens the card reports, the print history and expired cards included, only with cards.view', function () {
    $cards = ['card_prints', 'expired_cards', 'cards'];

    expect(($this->access)(($this->roleWith)(['reports.view', 'reports.export', 'cards.view'])))->toBe(($this->only)($cards, [true, 200, 200], [false, 403, 403]))
        ->and(($this->access)(($this->roleWith)(['reports.view', 'cardholders.view'])))->toBe(($this->only)(['cardholders', 'family_members', 'qualifications'], [true, 200, 403], [false, 403, 403]));
});

it('saves the organization\'s default columns of a report only for those who may see that report', function () {
    $columns = ['columns' => ['holder.name', 'card.number']];

    Sanctum::actingAs(($this->roleWith)(['reports.view', 'settings.update']));
    $this->putJson('/api/v1/reports/card-prints/columns', $columns)->assertForbidden();
    $this->putJson('/api/v1/reports/expired-cards/columns', $columns)->assertForbidden();

    Sanctum::actingAs(($this->roleWith)(['settings.update', 'cards.view']));
    $this->putJson('/api/v1/reports/card-prints/columns', $columns)->assertForbidden();

    Sanctum::actingAs(($this->roleWith)(['reports.view', 'settings.update', 'cards.view']));
    $this->putJson('/api/v1/reports/card-prints/columns', $columns)->assertOk()->assertJsonPath('data.default_columns', $columns['columns']);
    $this->putJson('/api/v1/reports/no-such-report/columns', $columns)->assertNotFound();
});
