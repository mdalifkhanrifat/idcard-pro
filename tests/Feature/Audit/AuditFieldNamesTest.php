<?php

use App\Models\Tenant;
use App\Services\Audit\AuditChangeDescriber;
use App\Support\Audit\JsonChanges;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * Every field the audit log records, by log name: what the models log, and what services log by hand.
 *
 * @return array<string, list<string>>
 */
function auditedFields(): array
{
    $fields = ['user' => ['roles'], 'roles' => ['name', 'permissions']];
    foreach (File::files(app_path('Models')) as $file) {
        $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();
        if (! in_array(LogsActivity::class, class_uses_recursive($class), true)) {
            continue;
        }
        $options = (new $class)->getActivitylogOptions();
        $fields[$options->logName] = [...($fields[$options->logName] ?? []), ...array_diff($options->logAttributes, ['settings'])];
    }

    return $fields;
}

/**
 * Every settings path an organization can have, as the audit logs it ("settings.notifications.events.card_ready.enabled").
 *
 * @return list<string>
 */
function auditedSettings(): array
{
    $tenant = Tenant::factory()->make(['settings' => ['reports' => collect(config('reports.reports'))->map(fn () => ['columns' => ['x']])->all()]]);

    return array_keys(JsonChanges::between([], $tenant->effectiveSettings(), 'settings')['new']);
}

it('names every field and setting the audit log records, in both languages', function (string $locale) {
    app()->setLocale($locale);
    $describer = app(AuditChangeDescriber::class);

    // The models are found (a wrong trait name would find none and pass)
    expect(array_keys(auditedFields()))->toContain('cardholders', 'cards', 'tenant', 'appointments');

    $unnamed = [];
    foreach (auditedFields() as $log => $keys) {
        foreach ($keys as $key) {
            if ($describer->label($log, $key) === $key) {
                $unnamed[] = "{$log}.{$key}";
            }
        }
    }
    foreach (auditedSettings() as $path) {
        if ($describer->label('tenant', $path) === $path) {
            $unnamed[] = $path;
        }
    }

    expect($unnamed)->toBe([]);
})->with(['en', 'bn']);

it('names a setting by what each part of its path stands for', function () {
    $describer = app(AuditChangeDescriber::class);

    expect($describer->label('tenant', 'settings.notifications.events.card_ready.enabled'))->toBe('Card ready — Sending on this event')
        ->and($describer->label('tenant', 'settings.notifications.events.card_ready.templates.bn.sms'))->toBe('Card ready — Bangla (বাংলা) — SMS text')
        ->and($describer->label('cardholders', 'rank_id'))->toBe('Rank / grade');

    app()->setLocale('bn');
    expect($describer->label('tenant', 'settings.notifications.events.card_ready.enabled'))->toBe('কার্ড তৈরি — এই ঘটনায় বার্তা পাঠানো');
});

it('gives each changed field its name and its old and new value as text, never a hidden one', function () {
    $activity = new Activity(['log_name' => 'user']);
    $activity->attribute_changes = collect([
        'attributes' => ['name' => 'Janet', 'status' => true, 'roles' => ['Operator', 'Viewer'], 'password' => 'secret-new'],
        'old' => ['name' => 'Jane', 'status' => false, 'roles' => [], 'password' => 'secret-old'],
    ]);

    expect(app(AuditChangeDescriber::class)->fields($activity))->toBe([
        ['key' => 'name', 'label' => 'Name', 'old' => 'Jane', 'new' => 'Janet'],
        ['key' => 'status', 'label' => 'Status', 'old' => 'No', 'new' => 'Yes'],
        ['key' => 'roles', 'label' => 'Roles', 'old' => '—', 'new' => 'Operator, Viewer'],
        ['key' => 'password', 'label' => 'Password', 'old' => '(hidden)', 'new' => '(hidden)'],
    ]);

    // A record created has no old values; an event's own details are not field changes
    $created = new Activity(['log_name' => 'user']);
    $created->attribute_changes = collect(['attributes' => ['name' => 'Jane']]);
    $event = new Activity(['log_name' => 'reports', 'properties' => ['report' => 'cards']]);
    expect(app(AuditChangeDescriber::class)->fields($created))->toBe([['key' => 'name', 'label' => 'Name', 'old' => null, 'new' => 'Jane']])
        ->and(app(AuditChangeDescriber::class)->fields($event))->toBeNull();
});
