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
    ],

    'default_roles' => [
        'Owner' => '*',
        'Administrator' => [
            'users.view', 'users.create', 'users.update', 'users.delete',
            'roles.view', 'audit.view', 'settings.view',
        ],
        'Operator' => ['users.view'],
        'Viewer' => ['users.view', 'audit.view'],
    ],

];
