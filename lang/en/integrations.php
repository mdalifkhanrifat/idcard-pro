<?php

return [
    'person_only' => 'Only a person can do this, not an integration account.',
    'not_an_address' => 'This is not an address or a range (like 203.0.113.7 or 203.0.113.0/24).',
    'address_refused' => 'This account does not work from this address.',
    'days_beyond_most' => 'The usual length cannot be longer than the most a token may last.',
    'sync_fields' => [
        'not_a_map' => 'Give the HR field names as an object: their name => our field.',
        'bad_name' => '“:name” cannot be an HR field name: up to 100 characters, no dot, no spaces around it.',
        'unknown_field' => '“:name” stands for “:field”, which the HR sync does not take.',
        'twice' => 'Only one HR field name can stand for “:field” (:names).',
    ],
    'idempotency' => [
        'invalid' => 'The Idempotency-Key must be 1 to 255 visible characters (letters, digits, punctuation), such as a UUID.',
        'mismatch' => 'This Idempotency-Key was used for a different request. Use a new key for a new request.',
        'in_progress' => 'A request with this Idempotency-Key is still being worked on. Try again in a moment.',
    ],
];
