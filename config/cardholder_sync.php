<?php

/*
|--------------------------------------------------------------------------
| Cardholder sync (HR systems)
|--------------------------------------------------------------------------
|
| POST /api/v1/sync/cardholders takes a batch of people; each is found by
| employee number and created or updated on its own. A bigger list is sent
| as several requests (the api-tokens rate limit counts requests, not
| records). See docs/SYNC.md.
|
*/

return [

    // Records in one request
    'max_records' => (int) env('CARDHOLDER_SYNC_MAX_RECORDS', 500),

    // Family members in one record ("family")
    'max_family' => (int) env('CARDHOLDER_SYNC_MAX_FAMILY', 30),

    // The HR system's own field names an organization may keep (settings.integrations.sync_fields)
    'max_field_names' => 100,

    // The run log (cardholder_syncs): kept unless the organization chose to delete old runs (settings.integrations
    // sync_prune_log, sync_log_days), daily by app:prune-sync-runs
    'log_days' => ['min' => 7, 'max' => 3650],
    'defaults' => ['prune_log' => false, 'log_days' => 365],

];
