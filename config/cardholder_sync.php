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

];
