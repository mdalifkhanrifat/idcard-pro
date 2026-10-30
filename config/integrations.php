<?php

/*
|--------------------------------------------------------------------------
| Integrations
|--------------------------------------------------------------------------
|
| Accounts other systems work through (6.1). An organization sets how long a token lasts by default and at most
| (settings.integrations, within `limit`); these are the values until it does. A token always ends: an old one
| nobody remembers is how a system gets into the wrong hands.
|
*/

return [

    'tokens' => [
        'days' => 365,
        'max_days' => 1095,
        'limit' => 3650,
    ],

];
