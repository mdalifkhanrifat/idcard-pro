<?php

namespace App\Support\Media;

use RuntimeException;

/**
 * The uploaded content is not a single TrueType, OpenType, WOFF or WOFF2 font.
 */
class UnreadableFontException extends RuntimeException
{
    public function __construct(public readonly string $reason = 'unreadable')
    {
        parent::__construct("Unreadable font ({$reason}).");
    }
}
