<?php

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\CardholderStatus;
use App\Enums\CardStatus;
use App\Enums\CustomFieldType;
use App\Enums\EmploymentType;
use App\Enums\FamilyRelation;
use App\Enums\QualificationKind;
use App\Enums\TemplateAudience;
use App\Enums\TemplateOrientation;
use App\Enums\TenantStatus;
use App\Enums\UserKind;
use App\Enums\UserStatus;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Models\CardTemplate;
use App\Models\FamilyMember;
use App\Models\MasterDataItem;

/*
|--------------------------------------------------------------------------
| Audit log, as people read it (6.0)
|--------------------------------------------------------------------------
|
| How App\Services\Audit\AuditChangeDescriber names what an audit row changed. Fields are named where the app
| already names them (lang audit.fields, report fields, cardholder lists, validation attribute names); settings by
| their dot path in validation.attributes, wildcards included ("notifications.events.*.enabled"). Values (6.0b):
| a field cast to an enum by `enums`, other fields and settings by `values`, a reference ("rank_id") by the
| record it points to (`references`), days and moments by the model's date casts, numbers in the reader's digits.
|
*/

return [

    // Values never shown, whatever the record or setting (the last part of the key decides)
    'hidden' => ['password', 'remember_token', 'token', 'secret', 'two_factor_secret', 'two_factor_recovery_codes'],

    // A wildcard part of a settings path, named by what it stands for: "notifications.events.card_ready.enabled"
    // reads "Card ready — Send messages for this event". `:segment` is the part; unknown parts keep their key.
    'segments' => [
        'notifications.events.*' => 'notifications.events.:segment.name',
        'notifications.events.*.templates.*' => 'notifications.languages.:segment',
        'biometrics.modalities.*' => 'biometrics.modalities.:segment',
        'appointments.services.*' => 'appointments.services.:segment',
        'reports.*' => 'reports.names.:segment',
    ],

    // The names of an enum's cases (a lang array: case value => name), for any field cast to it
    'enums' => [
        AppointmentStatus::class => 'appointments.statuses',
        AppointmentSource::class => 'appointments.sources',
        CardStatus::class => 'reports.statuses',
        CardholderStatus::class => 'reports.values.cardholder_status',
        CustomFieldType::class => 'audit.values.custom_field_type',
        EmploymentType::class => 'cards.values.employment_type',
        UserKind::class => 'audit.values.user_kind',
        FamilyRelation::class => 'cards.values.relation',
        QualificationKind::class => 'reports.values.qualification_kind',
        TemplateAudience::class => 'reports.values.audience',
        TemplateOrientation::class => 'reports.values.orientation',
        TenantStatus::class => 'audit.values.tenant_status',
        UserStatus::class => 'reports.values.user_status',
    ],

    // Values named by a lang array (value => name), by "<log>.<field>" ('*' for any log) or "settings.<path>"
    // (wildcards as in the settings names), or by several lang arrays tried in order; a list is named item by item.
    // Unknown values stay as they are.
    'values' => [
        '*.locale' => 'audit.values.locale',
        'appointments.service' => 'appointments.services',
        'appointment_locations.services' => 'appointments.services',
        'biometrics.modality' => 'biometrics.modalities',
        'biometrics.position' => 'biometrics.positions',
        'biometrics.unavailable_reason' => 'biometrics.reasons',
        'biometrics.status' => 'audit.values.capture_status',
        'biometrics.driver' => 'audit.values.biometric_driver',
        'cards.reason' => 'cards.reasons',
        'cardholder_files.collection' => 'audit.values.file_collection',
        'custom_fields.entity' => 'audit.values.custom_field_entity',
        'master_data.type' => 'reports.master_data_types',
        'settings.locale' => 'audit.values.locale',
        'settings.card_number_on_reissue' => 'audit.values.number_on_reissue',
        'settings.verification.fields' => ['reports.fields', 'reports.columns', 'audit.values.card_detail'],
        'settings.biometrics.modalities.*.mode' => 'audit.values.biometric_mode',
        'settings.biometrics.modalities.*.positions' => 'biometrics.positions',
        'settings.biometrics.modalities.*.drivers' => 'audit.values.biometric_driver',
        'settings.notifications.channels' => 'notifications.channels',
        'settings.notifications.languages' => 'notifications.languages',
        'settings.notifications.sms_languages' => 'notifications.sms_languages',
        'settings.notifications.events.*.channels' => 'notifications.channels',
        'settings.appointments.booking' => 'appointments.booking_modes',
        'settings.appointments.scheduling' => 'appointments.scheduling',
        'settings.appointments.confirmation' => 'appointments.confirmation',
        'settings.appointments.verification.method' => 'appointments.verification',
        'settings.appointments.verification.code_channels' => 'notifications.channels',
        'settings.reports.*.columns' => 'reports.columns',
    ],

    // Values of two parts joined by a dot ("cards.view"), each part named by its own lang array: "Issued cards: View"
    'joined' => [
        'roles.permissions' => ['audit.values.permission_module', 'audit.values.permission_ability'],
    ],

    // A reference ("rank_id", "location_id") is named by the record it points to (its model's relation named after
    // the field, or `relations`): the model and the column that names it. One query per model for a whole page.
    'references' => [
        MasterDataItem::class => 'name',
        Cardholder::class => 'full_name',
        FamilyMember::class => 'name',
        AppointmentLocation::class => 'name',
        CardTemplate::class => 'name',
    ],

    // References whose relation is not named after the field: "<log>.<field>" => relation
    'relations' => [
        'cards.card_template_id' => 'template',
    ],

];
