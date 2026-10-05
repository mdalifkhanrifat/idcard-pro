<?php

namespace App\Enums;

/**
 * State of an issued card. Only one card per holder (and per number) is active at a time.
 */
enum CardStatus: string
{
    case Active = 'active';
    // A newer card was issued to the same holder
    case Replaced = 'replaced';
    // Cancelled, e.g. reported lost or the holder left
    case Revoked = 'revoked';
}
