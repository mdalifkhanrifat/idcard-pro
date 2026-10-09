<?php

namespace App\Repositories\Contracts;

/**
 * Per-tenant running counters for card numbers.
 */
interface CardSequenceRepositoryInterface
{
    /**
     * Increment the counter under a row lock and return the new value.
     * Must be called inside a database transaction.
     */
    public function increment(string $key): int;

    /**
     * The value the next increment will return, without changing anything.
     */
    public function peekNext(string $key): int;

    /**
     * Move the counter up to at least this value (never down), e.g. past an imported number.
     * Must be called inside a database transaction.
     */
    public function advanceTo(string $key, int $value): void;
}
