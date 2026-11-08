<?php

namespace App\Repositories\Eloquent;

use App\Models\IdempotencyKey;
use App\Repositories\Contracts\IdempotencyKeyRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * @extends EloquentRepository<IdempotencyKey>
 */
class IdempotencyKeyRepository extends EloquentRepository implements IdempotencyKeyRepositoryInterface
{
    protected function model(): string
    {
        return IdempotencyKey::class;
    }

    public function claim(array $attributes): ?IdempotencyKey
    {
        try {
            return IdempotencyKey::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public function findFor(int $userId, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()->where('user_id', $userId)->where('key', $key)->first();
    }

    public function reclaim(IdempotencyKey $record, string $fingerprint, Carbon $expiresAt): bool
    {
        $now = now();
        $won = IdempotencyKey::query()
            ->whereKey($record->id)
            ->where('updated_at', $record->updated_at)
            ->update(['fingerprint' => $fingerprint, 'status_code' => null, 'response_body' => null, 'content_type' => null, 'expires_at' => $expiresAt, 'created_at' => $now, 'updated_at' => $now]) === 1;

        if ($won) {
            $record->refresh();
        }

        return $won;
    }
}
