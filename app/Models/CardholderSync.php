<?php

namespace App\Models;

use App\Enums\CardholderSyncKind;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CardholderSyncFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One run of an HR sync: who sent it, when, what kind (CardholderSyncKind), and how many people were created,
 * updated (or deactivated), left as they were, not found or refused. What was sent is never kept.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int|null $user_id
 * @property CardholderSyncKind $kind
 * @property int $total
 * @property int $created_count
 * @property int $updated_count
 * @property int $unchanged_count
 * @property int $failed_count
 * @property int $not_found_count deactivation only: numbers that matched nobody
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read User|null $user
 */
#[Fillable(['user_id', 'kind', 'total', 'created_count', 'updated_count', 'unchanged_count', 'failed_count', 'not_found_count', 'started_at', 'finished_at'])]
class CardholderSync extends Model
{
    /** @use HasFactory<CardholderSyncFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /**
     * The database's defaults, so a run just started (or one that broke off) reads as the row does.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'upsert',
        'created_count' => 0,
        'updated_count' => 0,
        'unchanged_count' => 0,
        'failed_count' => 0,
        'not_found_count' => 0,
    ];

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
            'kind' => CardholderSyncKind::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
