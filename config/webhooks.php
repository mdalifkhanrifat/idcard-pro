<?php

/*
|--------------------------------------------------------------------------
| Webhooks
|--------------------------------------------------------------------------
|
| What an organization can be told about, and how (6.2). A new event is one line in `events` (grouped by what it is
| about) and its name in lang/{en,bn}/webhooks.php → `events`, whose keys hold dots: read the list whole.
|
| `allow_private` opens addresses inside this network and plain http, for a receiver of one's own while developing.
| It belongs to the installation, never to an organization: it is what keeps a webhook from reaching this server's
| own services.
|
| `only_hosts`, when it names any, is the only place this installation sends to: a development machine lists its own
| receiver, so nothing can leave it whatever address an organization saves. Empty in production.
|
| An address is given `connect_timeout` seconds to connect and `timeout` seconds to answer. Whatever it answers beyond
| the status is thrown away.
|
*/

return [

    'events' => [
        'cardholder' => ['cardholder.created', 'cardholder.updated', 'cardholder.deleted'],
        'card' => ['card.issued', 'card.printed', 'card.updated', 'card.revoked'],
        'appointment' => ['appointment.booked', 'appointment.confirmed', 'appointment.moved', 'appointment.cancelled', 'appointment.status_changed'],
    ],

    'max_per_organization' => 20,

    'allow_private' => (bool) env('WEBHOOKS_ALLOW_PRIVATE', false),

    'only_hosts' => array_values(array_filter(array_map(trim(...), explode(',', strtolower((string) env('WEBHOOKS_ONLY_HOSTS', '')))))),

    'connect_timeout' => 5,

    'timeout' => 15,

];
