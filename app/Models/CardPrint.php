<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CardPrintFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One printing of an issued card (legacy card_print_history). This table is itself the print log,
 * so it is not written to the activity log as well.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $card_id
 * @property string|null $batch shared by cards printed together
 * @property string $method key of config('cards.print.methods'), or "import" (printed before the card came into this system)
 * @property int $copies
 * @property string|null $note
 * @property int|null $printed_by
 * @property Carbon $printed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Card $card
 * @property-read User|null $printer
 */
#[Fillable(['card_id', 'batch', 'method', 'copies', 'note', 'printed_by', 'printed_at'])]
class CardPrint extends Model
{
    /** @use HasFactory<CardPrintFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /**
     * Logged by an import for a printing that happened outside this system; not a way of printing here.
     */
    public const METHOD_IMPORT = 'import';

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'copies' => 'integer',
            'printed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Card, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }
}
