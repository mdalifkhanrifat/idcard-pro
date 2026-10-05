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
        // Issued cards: issue a new card, print it, update its RF number or notes, revoke it
        'cards' => ['view', 'issue', 'print', 'update', 'revoke'],
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
            'cards.view', 'cards.issue', 'cards.print', 'cards.update', 'cards.revoke',
        ],
        // Legacy operators printed cards, so they issue them too
        'Operator' => ['users.view', 'master_data.view', 'cardholders.view', 'cardholders.create', 'cardholders.update', 'templates.view', 'cards.view', 'cards.issue', 'cards.print'],
        'Viewer' => ['users.view', 'audit.view', 'master_data.view', 'cardholders.view', 'cards.view'],
    ],

];
