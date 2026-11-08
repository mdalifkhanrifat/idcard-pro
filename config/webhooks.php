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
| Deliveries wait on a `queue` of their own, which the worker must listen to after the default one. A delivery is tried
| once and then after each of `retry.backoff` seconds, about nine hours in all. After `disable_after` deliveries in a
| row have failed the webhook turns itself off.
|
| An organization may try fewer times (settings.webhooks.attempts, from 1 to one more than the waits): it then keeps
| the first waits, never a schedule of its own, so no one can make this server knock every second. It may also turn
| its webhooks off sooner or later (settings.webhooks.disable_after, within `disable_limits`).
|
| The log of deliveries holds what was sent. It is kept for good unless the organization chooses to have deliveries
| older than `log_days` deleted (daily, app:prune-webhook-deliveries).
|
*/

return [

    'events' => [
        'cardholder' => ['cardholder.created', 'cardholder.updated', 'cardholder.deleted', 'cardholder.imported', 'cardholder.synced', 'cardholder.family_changed', 'cardholder.photo_changed'],
        'card' => ['card.issued', 'card.printed', 'card.updated', 'card.revoked'],
        'appointment' => ['appointment.booked', 'appointment.confirmed', 'appointment.moved', 'appointment.cancelled', 'appointment.status_changed'],
    ],

    // An event that says in one delivery what a whole batch did: a file, or one request of an HR sync. A webhook that
    // chooses it hears nothing of these events from that batch; a webhook that does not goes on hearing of each record,
    // so choosing it is what changes anything.
    'batches' => [
        'cardholder.imported' => ['cardholder.created', 'cardholder.updated'],
        'cardholder.synced' => ['cardholder.created', 'cardholder.updated'],
    ],

    // What a person must be allowed to see to send a group's events somewhere: a webhook hands that data over
    'requires' => [
        'cardholder' => 'cardholders.view',
        'card' => 'cards.view',
        'appointment' => 'appointments.view',
    ],

    'max_per_organization' => 20,

    'allow_private' => (bool) env('WEBHOOKS_ALLOW_PRIVATE', false),

    'only_hosts' => array_values(array_filter(array_map(trim(...), explode(',', strtolower((string) env('WEBHOOKS_ONLY_HOSTS', '')))))),

    'connect_timeout' => 5,

    'timeout' => 15,

    'queue' => 'webhooks',

    'retry' => ['backoff' => [60, 300, 1800, 7200, 21600]],

    'disable_after' => 20,

    'disable_limits' => ['min' => 1, 'max' => 100],

    // Test deliveries by hand, per user and minute
    'test_per_minute' => 5,

    // Failed deliveries sent again by hand, per user and minute
    'resend_per_minute' => 30,

    // Days a delivery stays in the log when the organization has old ones deleted (within these)
    'log_days' => ['min' => 7, 'max' => 3650],

    'defaults' => [
        'prune_log' => false,
        'log_days' => 365,
    ],

];
