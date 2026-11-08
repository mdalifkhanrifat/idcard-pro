<?php

/*
|--------------------------------------------------------------------------
| Idempotency-Key
|--------------------------------------------------------------------------
|
| A system that sends a request with an Idempotency-Key header and sends it
| again (it timed out, it does not know whether it worked) gets the first
| answer back, and the work is done once. Routes take part with the
| `idempotent` middleware. A key is the account's own and is kept for
| `keep_hours`. A request still being worked on after `stale_seconds` (its
| process died) gives its key up to the next try.
|
*/

return [

    'keep_hours' => (int) env('IDEMPOTENCY_KEEP_HOURS', 24),

    'stale_seconds' => 600,

    // Visible ASCII, as a UUID or any token of the sender's own
    'key_pattern' => '/^[\x21-\x7E]{1,255}$/',

];
