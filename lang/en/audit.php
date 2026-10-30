<?php

return [
    // A value the audit never shows (config audit.hidden)
    'hidden' => '(hidden)',

    // The audit's own names of a record's fields, by log name; '*' for any log. Fields not here are named as
    // reports, the cardholder lists or validation name them (App\Services\Audit\AuditChangeDescriber).
    'fields' => [
        '*' => [
            'name' => 'Name',
            'name_local' => 'Name (local language)',
            'is_active' => 'Active',
            'phone' => 'Phone',
            'locale' => 'Language',
            'title' => 'Title',
            'original_name' => 'File name',
            'replaced_at' => 'Replaced at',
            'card_type_id' => 'Card type',
            'password' => 'Password',
            'authority_type_id' => 'Authority type',
        ],
        'user' => [
            'kind' => 'Account type',
            'description' => 'Description',
            'username' => 'Username',
            'roles' => 'Roles',
        ],
        'roles' => [
            'name' => 'Role name',
            'permissions' => 'Permissions',
        ],
        'tenant' => [
            'slug' => 'Short name (in links)',
            'legal_name' => 'Legal name',
            'timezone' => 'Time zone',
            'primary_color' => 'Primary colour',
            'logo_path' => 'Logo',
        ],
        'appointments' => [
            'number' => 'Booking no',
            'starts_at' => 'Starts at',
            'ends_at' => 'Ends at',
            'cancel_reason' => 'Why called off',
        ],
        'biometrics' => [
            'modality' => 'Kind',
            'position' => 'Position',
            'unavailable_reason' => 'Why not taken',
            'driver' => 'Taken by',
            'quality' => 'Quality',
            'low_quality' => 'Low quality',
        ],
        'cards' => [
            'number' => 'Card no',
            'rf_card_no' => 'RF card no',
            'card_template_id' => 'Template',
            'template_version' => 'Template version',
            'issue_date' => 'Issue date',
            'expiry_date' => 'Expiry date',
            'status_note' => 'Status note',
        ],
        'card_templates' => [
            'orientation' => 'Orientation',
            'width' => 'Width',
            'height' => 'Height',
            'size_unit' => 'Size unit',
            'validity_months' => 'Validity (months)',
            'version' => 'Version',
        ],
        'cardholder_files' => [
            'collection' => 'Kind of file',
        ],
        'custom_fields' => [
            'entity' => 'Belongs to',
            'key' => 'Key',
            'label' => 'Label',
            'type' => 'Type',
            'is_required' => 'Required',
        ],
        'family_members' => [
            'relation' => 'Relation',
            'card_no' => 'Card no',
        ],
        'master_data' => [
            'type' => 'List',
        ],
        'qualifications' => [
            'kind' => 'Kind',
            'institution' => 'Institution',
            'year' => 'Year',
            'result' => 'Result',
        ],
        'signatories' => [
            'signature_path' => 'Signature image',
            'effective_from' => 'In effect from',
            'effective_to' => 'In effect until',
        ],
        'fonts' => [
            'family' => 'Font family',
            'style' => 'Style',
        ],
        'images' => [
            'path' => 'Image file',
        ],
    ],
    // Names of values the app names only on its pages (config audit.values)
    'values' => [
        'locale' => ['en' => 'English', 'bn' => 'Bangla'],
        'user_kind' => ['person' => 'Person', 'integration' => 'Integration'],
        'permission_module' => ['users' => 'Users', 'roles' => 'Roles', 'audit' => 'Audit log', 'settings' => 'Organization settings', 'master_data' => 'Master data', 'cardholders' => 'Cardholders', 'signatories' => 'Signatories', 'templates' => 'Card templates', 'fonts' => 'Fonts', 'images' => 'Image library', 'cards' => 'Issued cards', 'biometrics' => 'Biometrics', 'reports' => 'Reports', 'notifications' => 'Notifications', 'appointments' => 'Appointments', 'integrations' => 'Integrations'],
        'permission_ability' => ['view' => 'View', 'create' => 'Create', 'update' => 'Edit', 'delete' => 'Delete', 'import' => 'Import', 'issue' => 'Issue', 'print' => 'Print', 'revoke' => 'Revoke', 'enroll' => 'Enroll', 'override_requirements' => 'Override requirements', 'export' => 'Export', 'send' => 'Send', 'book' => 'Book', 'manage' => 'Manage'],
        'tenant_status' => ['active' => 'Active', 'suspended' => 'Suspended'],
        'custom_field_type' => ['text' => 'Text', 'textarea' => 'Long text', 'number' => 'Number', 'date' => 'Date', 'select' => 'Drop-down', 'checkbox' => 'Yes / no'],
        'custom_field_entity' => ['cardholder' => 'Cardholder', 'family_member' => 'Family member'],
        'capture_status' => ['captured' => 'Captured', 'unavailable' => 'Cannot be captured'],
        'biometric_driver' => ['upload' => 'File upload', 'simulator' => 'Simulator', 'webcam' => 'Webcam', 'secugen' => 'SecuGen', 'bridge' => 'Bridge agent'],
        'biometric_mode' => ['off' => 'Off', 'optional' => 'Optional', 'required' => 'Required'],
        'file_collection' => ['photo' => 'Photo', 'signature' => 'Signature', 'document' => 'Document'],
        'number_on_reissue' => ['keep' => 'Keep the current number', 'new' => 'Always a new number'],
        'card_detail' => ['photo' => 'Photo', 'template' => 'Template'],
    ],

    // A record a reference points to that no longer exists
    'deleted_record' => '#:id (deleted)',
];
