<?php

namespace App\Services\Idempotency;

use RuntimeException;

/**
 * An Idempotency-Key that cannot be used for this request: another request's (422), or still being worked on (409).
 */
final class IdempotencyConflict extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
