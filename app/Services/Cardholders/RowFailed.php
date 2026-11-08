<?php

namespace App\Services\Cardholders;

use RuntimeException;

/**
 * Rolls back the transaction of a record (an import row, a synced person) that did not pass
 * validation, carrying its outcome out (CardholderSyncService::atomically()).
 *
 * @internal
 */
final class RowFailed extends RuntimeException
{
    /**
     * @param  array{messages: array<int, string>}  $outcome
     */
    public function __construct(public readonly array $outcome)
    {
        parent::__construct('Import row failed validation.');
    }
}
