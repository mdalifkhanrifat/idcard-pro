<?php

namespace App\Repositories\Contracts;

use App\Models\IdempotencyKey;
use Illuminate\Support\Carbon;

/**
 * @extends RepositoryInterface<IdempotencyKey>
 */
interface IdempotencyKeyRepositoryInterface extends RepositoryInterface
{
    /**
     * Claim a key for a request now starting; null when the account already holds it.
     *
     * @param  array{tenant_id: int|null, user_id: int, key: string, fingerprint: string, expires_at: Carbon}  $attributes
     */
    public function claim(array $attributes): ?IdempotencyKey;

    public function findFor(int $userId, string $key): ?IdempotencyKey;

    /**
     * Take over a key that is past its time (expired, or its request died) for a new request: only one of several
     * tries at once wins (the row must still be as it was read).
     */
    public function reclaim(IdempotencyKey $record, string $fingerprint, Carbon $expiresAt): bool;
}
