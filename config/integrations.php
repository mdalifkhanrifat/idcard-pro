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
| `requests` is how many API calls one token's account may make a minute (settings.integrations.requests_per_minute,
| within `min` and `max`): a system set up wrongly should not fill the server. Limits people in a browser not at all.
|
*/

return [

    'tokens' => [
        'days' => 365,
        'max_days' => 1095,
        'limit' => 3650,
    ],

    'requests' => [
        'per_minute' => 600,
        'min' => 10,
        'max' => 6000,
    ],

];
