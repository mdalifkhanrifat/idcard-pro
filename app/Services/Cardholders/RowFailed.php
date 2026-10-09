<?php

namespace App\Services\Cardholders;

use RuntimeException;

/**
 * Rolls back the transaction of an import row that did not pass validation.
 *
 * @internal
 */
final class RowFailed extends RuntimeException
{
    /**
     * @param  array{messages: array<int, string>, action: string, card: 'imported'|'skipped'|null, lookups: array<int, string>, preview: array<string, string>}  $outcome
     */
    public function __construct(public readonly array $outcome)
    {
        parent::__construct('Import row failed validation.');
    }
}
