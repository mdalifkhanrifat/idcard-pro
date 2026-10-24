<?php

/*
|--------------------------------------------------------------------------
| Audit log, as people read it (6.0)
|--------------------------------------------------------------------------
|
| How App\Services\Audit\AuditChangeDescriber names what an audit row changed. Fields are named where the app
| already names them (lang audit.fields, report fields, cardholder lists, validation attribute names); settings by
| their dot path in validation.attributes, wildcards included ("notifications.events.*.enabled").
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

];
