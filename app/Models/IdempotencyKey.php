<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The answer to a request sent with an Idempotency-Key, kept until it expires (pruned daily by model:prune).
 * Keyed by account, not by organization scope: the account is what a key belongs to.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $user_id
 * @property string $key
 * @property string $fingerprint
 * @property int|null $status_code
 * @property string|null $response_body
 * @property string|null $content_type
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tenant_id', 'user_id', 'key', 'fingerprint', 'status_code', 'response_body', 'content_type', 'expires_at'])]
class IdempotencyKey extends Model
{
    use MassPrunable;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now());
    }

    /**
     * The first request has answered; until then it is still being worked on.
     */
    public function isAnswered(): bool
    {
        return $this->status_code !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
