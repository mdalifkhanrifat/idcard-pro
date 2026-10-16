<?php

/*
|--------------------------------------------------------------------------
| Permission registry
|--------------------------------------------------------------------------
|
| Single source of truth for every permission in the application. Each new
| module registers its abilities here and `php artisan app:sync-permissions`
| writes them to the database. Names follow "<module>.<ability>".
|
| `default_roles` are created for every new tenant. A value of '*' grants
| every registered permission.
|
*/

return [

    'modules' => [
        'users' => ['view', 'create', 'update', 'delete'],
        'roles' => ['view', 'create', 'update', 'delete'],
        'audit' => ['view'],
        'settings' => ['view', 'update'],
        'master_data' => ['view', 'create', 'update', 'delete'],
        'cardholders' => ['view', 'create', 'update', 'delete', 'import'],
        'signatories' => ['view', 'create', 'update', 'delete'],
        'templates' => ['view', 'create', 'update', 'delete'],
        // Licensed fonts for card templates; anyone with templates.view can also use (list) them
        'fonts' => ['view', 'create', 'delete'],
        // Image library (logos, backgrounds) linked from templates; templates.view may also list it
        'images' => ['view', 'create', 'update', 'delete'],
        // Issued cards: issue a new card, print it, update its RF number or notes, revoke it, and issue or print
        // although something the organization requires is missing (with a reason, kept in the audit log)
        'cards' => ['view', 'issue', 'print', 'update', 'revoke', 'override_requirements'],
        // Biometric captures (Phase 4): see them, capture or mark a position unavailable, delete them
        'biometrics' => ['view', 'enroll', 'delete'],
        // Reports (Phase 5): see them, and download them as files (each download is kept in the audit log)
        'reports' => ['view', 'export'],
        // Notifications (Phase 5): see the log of sent messages (full address and text), and send by hand —
        // resend a failed or skipped message, or a test message to check a channel before turning it on
        'notifications' => ['view', 'send'],
        // Appointments (Phase 5): see them, book (and change or cancel) one for a person, and manage the day —
        // approve, check in, no-show, done. Their settings, places and closed days: settings.update
        'appointments' => ['view', 'book', 'manage'],
    ],

    'default_roles' => [
        'Owner' => '*',
        'Administrator' => [
            'users.view', 'users.create', 'users.update', 'users.delete',
            'roles.view', 'audit.view', 'settings.view',
            'master_data.view', 'master_data.create', 'master_data.update', 'master_data.delete',
            'cardholders.view', 'cardholders.create', 'cardholders.update', 'cardholders.delete', 'cardholders.import',
            'signatories.view', 'signatories.create', 'signatories.update', 'signatories.delete',
            'templates.view', 'templates.create', 'templates.update', 'templates.delete',
            'fonts.view', 'fonts.create', 'fonts.delete',
            'images.view', 'images.create', 'images.update', 'images.delete',
            'cards.view', 'cards.issue', 'cards.print', 'cards.update', 'cards.revoke', 'cards.override_requirements',
            'biometrics.view', 'biometrics.enroll', 'biometrics.delete',
            'reports.view', 'reports.export',
            'notifications.view', 'notifications.send',
            'appointments.view', 'appointments.book', 'appointments.manage',
        ],
        // Legacy operators printed cards, so they issue them too
        'Operator' => ['users.view', 'master_data.view', 'cardholders.view', 'cardholders.create', 'cardholders.update', 'templates.view', 'cards.view', 'cards.issue', 'cards.print', 'biometrics.view', 'biometrics.enroll', 'appointments.view', 'appointments.book'],
        'Viewer' => ['users.view', 'audit.view', 'master_data.view', 'cardholders.view', 'cards.view', 'reports.view', 'notifications.view', 'appointments.view'],
    ],

];
