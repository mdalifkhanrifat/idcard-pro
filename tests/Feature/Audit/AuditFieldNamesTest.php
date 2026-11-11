<?php

use App\Models\Appointment;
use App\Models\BiometricCapture;
use App\Models\Card;
use App\Models\Cardholder;
use App\Models\CardholderFile;
use App\Models\CustomField;
use App\Models\MasterDataItem;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Webhook;
use App\Services\Appointments\AppointmentSettings;
use App\Services\Audit\AuditChangeDescriber;
use App\Services\Webhooks\WebhookEvents;
use App\Support\Audit\JsonChanges;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
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
    $fields = ['user' => ['roles'], 'roles' => ['name', 'permissions'], 'api_tokens' => ['owner', 'name', 'expires_at']];
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

    // Every path, the empty ones too (a change from nothing to nothing is never logged, but a value may come later)
    return array_keys(JsonChanges::flatten($tenant->effectiveSettings(), 'settings'));
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

/**
 * What the audit shows as a field's new value.
 */
function describedValue(string $log, string $subject, string $key, mixed $value): ?string
{
    $activity = new Activity(['log_name' => $log, 'subject_type' => $subject]);
    $activity->attribute_changes = collect(['attributes' => [$key => $value]]);

    return app(AuditChangeDescriber::class)->fields($activity)[0]['new'];
}

it('names every case of every enum a logged field holds, in both languages', function (string $locale) {
    app()->setLocale($locale);

    $unnamed = [];
    foreach (File::files(app_path('Models')) as $file) {
        $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();
        if (! in_array(LogsActivity::class, class_uses_recursive($class), true)) {
            continue;
        }
        $model = new $class;
        foreach ($model->getActivitylogOptions()->logAttributes as $field) {
            $cast = $model->getCasts()[$field] ?? null;
            if (! is_string($cast) || ! enum_exists($cast)) {
                continue;
            }
            foreach ($cast::cases() as $case) {
                if (describedValue('x', $class, $field, $case->value) === $case->value) {
                    $unnamed[] = class_basename($class).".{$field}={$case->value}";
                }
            }
        }
    }

    expect($unnamed)->toBe([]);
})->with(['en', 'bn']);

it('names every choice a logged field or setting offers, in both languages', function (string $locale) {
    app()->setLocale($locale);
    $choices = [
        ['tenant', Tenant::class, 'settings.verification.fields', config('cards.verification.fields')],
        ['tenant', Tenant::class, 'settings.notifications.channels', array_keys(config('notifications.channels'))],
        ['tenant', Tenant::class, 'settings.notifications.events.card_ready.channels', array_keys(config('notifications.channels'))],
        ['tenant', Tenant::class, 'settings.notifications.languages', config('notifications.languages')],
        ['tenant', Tenant::class, 'settings.notifications.sms_languages', config('notifications.sms_languages')],
        ['tenant', Tenant::class, 'settings.biometrics.modalities.face.mode', config('biometrics.modes')],
        ['tenant', Tenant::class, 'settings.biometrics.modalities.face.drivers', array_keys(config('biometrics.drivers'))],
        ['tenant', Tenant::class, 'settings.biometrics.modalities.fingerprint.positions', array_keys(config('biometrics.modalities.fingerprint.positions'))],
        ['tenant', Tenant::class, 'settings.card_number_on_reissue', config('cards.number_on_reissue')],
        ['tenant', Tenant::class, 'settings.appointments.booking', AppointmentSettings::bookingModes()],
        ['tenant', Tenant::class, 'settings.appointments.scheduling', AppointmentSettings::schedulingModes()],
        ['tenant', Tenant::class, 'settings.appointments.confirmation', AppointmentSettings::confirmationModes()],
        ['tenant', Tenant::class, 'settings.appointments.verification.method', AppointmentSettings::verificationMethods()],
        ['tenant', Tenant::class, 'settings.appointments.verification.code_channels', AppointmentSettings::codeChannels()],
        ['tenant', Tenant::class, 'locale', config('notifications.languages')],
        ['user', User::class, 'locale', config('notifications.languages')],
        ['biometrics', BiometricCapture::class, 'status', [BiometricCapture::STATUS_CAPTURED, BiometricCapture::STATUS_UNAVAILABLE]],
        ['biometrics', BiometricCapture::class, 'driver', array_keys(config('biometrics.drivers'))],
        ['biometrics', BiometricCapture::class, 'modality', array_keys(config('biometrics.modalities'))],
        ['biometrics', BiometricCapture::class, 'unavailable_reason', config('biometrics.unavailable_reasons')],
        ['cards', Card::class, 'reason', array_keys(config('cards.reasons'))],
        ['cardholder_files', CardholderFile::class, 'collection', array_keys(config('cardholder_files.collections'))],
        ['custom_fields', CustomField::class, 'entity', CustomField::ENTITIES],
        ['master_data', MasterDataItem::class, 'type', array_keys(config('master_data.types'))],
        ['appointments', Appointment::class, 'service', array_keys(config('appointments.services'))],
        ['webhooks', Webhook::class, 'events', WebhookEvents::keys()],
    ];

    $unnamed = [];
    foreach ($choices as [$log, $subject, $key, $values]) {
        foreach ($values as $value) {
            if (describedValue($log, $subject, $key, $value) === $value) {
                $unnamed[] = "{$log}.{$key}={$value}";
            }
        }
    }

    expect($unnamed)->toBe([]);
})->with(['en', 'bn']);

it('names every permission in both languages, as the roles page does', function (string $locale) {
    app()->setLocale($locale);
    $page = json_decode(File::get(resource_path("js/locales/{$locale}.json")), true)['roles'];

    foreach (config('permissions.modules') as $module => $abilities) {
        foreach ($abilities as $ability) {
            expect(describedValue('roles', Role::class, 'permissions', "{$module}.{$ability}"))
                ->toBe("{$page['modules'][$module]}: {$page['abilities'][$ability]}");
        }
    }
})->with(['en', 'bn']);

it('shows the record a reference points to, days as days and moments in the organization\'s time, in the reader\'s digits', function () {
    [$tenant] = tenantWithOwner();
    $tenant->update(['timezone' => 'Asia/Dhaka']);
    app(TenantContext::class)->set($tenant->fresh());
    $rank = masterItem($tenant, ['type' => 'rank', 'name' => 'Sergeant']);
    $unit = masterItem($tenant, ['type' => 'unit', 'name' => 'Base Unit']);
    $activity = fn (array $old, array $new) => tap(new Activity(['log_name' => 'cardholders', 'subject_type' => Cardholder::class]), fn (Activity $a) => $a->attribute_changes = collect(['old' => $old, 'attributes' => $new]));
    $rows = [
        $activity(['rank_id' => null, 'joining_date' => '2026-10-24T00:00:00.000000Z'], ['rank_id' => $rank->id, 'joining_date' => '2026-10-25T00:00:00.000000Z']),
        $activity(['unit_id' => 999999], ['unit_id' => $unit->id]),
    ];

    $describer = app(AuditChangeDescriber::class);
    DB::enableQueryLog();
    $describer->prime($rows);
    $fields = [...$describer->fields($rows[0]), ...$describer->fields($rows[1])];
    // One query for both pointed-to records (and the one no longer there), none while describing
    expect(count(DB::getQueryLog()))->toBe(1);

    expect(collect($fields)->keyBy('key')->map(fn (array $field) => [$field['old'], $field['new']])->all())->toBe([
        'rank_id' => ['—', 'Sergeant'],
        // A day is a day: never moved by a time zone
        'joining_date' => ['24/10/2026', '25/10/2026'],
        'unit_id' => ['#999999 (deleted)', 'Base Unit'],
    ]);

    // A moment in the organization's time: 18:30 UTC is already the next day in Dhaka; Bangla digits for Bangla readers
    app()->setLocale('bn');
    $moment = new Activity(['log_name' => 'biometrics', 'subject_type' => BiometricCapture::class]);
    $moment->attribute_changes = collect(['attributes' => ['replaced_at' => '2026-10-24T18:30:00.000000Z', 'quality' => 72, 'status' => 'captured']]);
    expect(array_column(app(AuditChangeDescriber::class)->fields($moment), 'new'))->toBe(['২৫/১০/২০২৬ ০০:৩০', '৭২', 'নেওয়া হয়েছে']);
});
