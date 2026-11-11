<?php

namespace App\Enums;

/**
 * What an HR sync request did: create or update people (POST /sync/cardholders) or mark people who
 * left as no longer active (POST /sync/cardholders/deactivate).
 */
enum CardholderSyncKind: string
{
    case Upsert = 'upsert';
    case Deactivate = 'deactivate';
}
