<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Login lockout
    |--------------------------------------------------------------------------
    |
    | After `max_attempts` consecutive failed logins the account is locked for
    | `lockout_minutes`. The counter is stored on the user record so clearing
    | cookies or switching browsers does not reset it.
    |
    */

    'lockout' => [
        'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('LOGIN_LOCKOUT_MINUTES', 15),
    ],

    'password' => [
        'min_length' => (int) env('PASSWORD_MIN_LENGTH', 10),
        'reset_token_minutes' => 60,
    ],

    'two_factor' => [
        'issuer' => env('APP_NAME', 'IDCard Pro'),
        'recovery_codes' => 8,
    ],

];
