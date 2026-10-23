<?php

use App\Enums\LoginEventType;
use App\Models\LoginEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    Sanctum::actingAs($this->owner);
    $this->holder = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => 'E-1']);
    $this->putJson("/api/v1/cardholders/{$this->holder->public_id}", ['full_name' => 'Janet Doe', 'employee_no' => 'E-1'])->assertOk();
    $event = fn (LoginEventType $type, string $ip, ?string $identifier = null) => LoginEvent::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $type === LoginEventType::Succeeded ? $this->owner->id : null, 'identifier' => $identifier ?? $this->owner->email, 'event' => $type, 'ip_address' => $ip, 'user_agent' => 'Test browser']);
    $event(LoginEventType::Failed, '10.0.0.9', 'nobody@example.com');
    $event(LoginEventType::Succeeded, '10.0.0.1');
    $event(LoginEventType::Succeeded, '10.0.0.1');
});

it('lists the changes the audit page lists, what changed in words', function () {
    $ids = fn (string $url) => array_column($this->getJson($url)->assertOk()->json('data'), 'id');
    foreach (['', 'filter[log_name]=cardholders', 'filter[event]=updated', "filter[causer]={$this->owner->public_id}", 'filter[date_from]=2000-01-01'] as $query) {
        expect($ids("/api/v1/reports/audit-changes?{$query}"))->toBe($ids("/api/v1/audit/activities?{$query}"), $query);
    }

    $row = $this->getJson('/api/v1/reports/audit-changes?filter[event]=updated&filter[log_name]=cardholders&columns[]=audit.who&columns[]=audit.module&columns[]=audit.event&columns[]=audit.subject&columns[]=audit.changes')->json('data.0.values');
    expect($row)->toMatchArray(['audit.who' => $this->owner->name, 'audit.module' => 'Cardholders', 'audit.event' => 'Updated', 'audit.subject' => 'Cardholder'])
        ->and($row['audit.changes'])->toContain('full_name: Jane Doe → Janet Doe');

    $filters = collect(collect($this->getJson('/api/v1/reports')->json('data'))->keyBy('key')['audit_changes']['filters'])->keyBy('key');
    expect(array_column($filters['log_name']['options'], 'label'))->toContain('Cardholders');
});

it('lists sign-ins with the audit page\'s criteria and totals', function () {
    $ids = fn (string $url) => array_column($this->getJson($url)->assertOk()->json('data'), 'id');
    foreach (['', 'filter[event]=failed', 'filter[identifier]=nobody', 'filter[ip_address]=10.0.0.1'] as $query) {
        expect($ids("/api/v1/reports/audit-logins?{$query}"))->toBe($ids("/api/v1/audit/logins?{$query}"), $query);
    }
    // The page shows the newest first; the report can also show the oldest first
    expect($ids('/api/v1/reports/audit-logins?sort=id'))->toBe(array_reverse($ids('/api/v1/audit/logins')));
    $this->getJson('/api/v1/reports/audit-logins?filter[event]=failed&columns[]=login.event&columns[]=login.identifier&columns[]=login.user&columns[]=login.ip_address')
        ->assertJsonPath('meta.totals', ['events' => 1, 'succeeded' => 0, 'addresses' => 1])
        ->assertJsonPath('data.0.values', ['login.event' => 'Failed', 'login.identifier' => 'nobody@example.com', 'login.user' => null, 'login.ip_address' => '10.0.0.9']);
    $this->getJson('/api/v1/reports/audit-logins')->assertJsonPath('meta.totals', ['events' => 3, 'succeeded' => 2, 'addresses' => 2]);
});

it('needs the audit.view permission, and keeps its own downloads in the log', function () {
    $this->get('/api/v1/reports/audit-logins/export?format=csv&filter[event]=failed&columns[]=login.identifier&columns[]=login.ip_address')
        ->assertOk()
        ->assertStreamedContent("\u{FEFF}\"Email, username or employee number tried\",\"IP address\"\nnobody@example.com,10.0.0.9\n");
    $changes = $this->getJson('/api/v1/reports/audit-changes?filter[event]=exported&columns[]=audit.changes')->assertJsonCount(1, 'data')->json('data.0.values');
    expect($changes['audit.changes'])->toContain('report: audit_logins', 'format: csv', 'rows: 1');

    $role = app(TenantContext::class)->run($this->tenant, fn () => Role::create(['name' => 'Report reader', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id]));
    $role->givePermissionTo(['reports.view']);
    Sanctum::actingAs(tenantUser($this->tenant, 'Report reader'));
    $this->getJson('/api/v1/reports/audit-changes')->assertForbidden();
    $this->getJson('/api/v1/reports/audit-logins')->assertForbidden();
});

it('names in the report every module and event the audit page names, in both languages', function () {
    foreach (['en', 'bn'] as $locale) {
        $audit = json_decode(file_get_contents(resource_path("js/locales/{$locale}.json")), true)['audit'];
        $values = trans('reports.values', locale: $locale);

        expect(array_diff(array_keys($audit['logs']), array_keys($values['audit_logs'])))->toBe([])
            ->and(array_diff(array_keys($audit['events']), array_keys($values['audit_events'])))->toBe([]);
    }
});
