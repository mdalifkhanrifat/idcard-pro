<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CardVerificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One public check of a card on the verification page (3.7). Pruned after config('cards.verification.log_days').
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $card_id
 * @property string $status valid, expired, revoked or replaced at the time
 * @property string $device keyed hash of the address, never the address itself
 * @property Carbon $verified_at
 * @property-read Card $card
 */
#[Fillable(['card_id', 'status', 'device', 'verified_at'])]
class CardVerification extends Model
{
    /** @use HasFactory<CardVerificationFactory> */
    use BelongsToTenant, HasFactory, MassPrunable;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->withoutGlobalScopes()->where('verified_at', '<', now()->subDays((int) config('cards.verification.log_days')));
    }

    /**
     * @return BelongsTo<Card, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
