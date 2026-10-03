<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A CSV/XLSX file of cardholders: analyzed first, then imported by a queued job.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int|null $user_id
 * @property string $original_name
 * @property string $disk
 * @property string|null $path
 * @property ImportStatus $status
 * @property array{create_missing_lookups: bool, update_existing: bool} $options
 * @property array<string, mixed>|null $summary
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $created_count
 * @property int $updated_count
 * @property int $failed_count
 * @property array<int, array{row: int, messages: array<int, string>}>|null $errors
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read User|null $user
 */
#[Fillable([
    'user_id', 'original_name', 'disk', 'path', 'status', 'options', 'summary', 'total_rows', 'valid_rows',
    'created_count', 'updated_count', 'failed_count', 'errors', 'started_at', 'finished_at',
])]
class CardholderImport extends Model
{
    use BelongsToTenant, HasUlids;

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
            'status' => ImportStatus::class,
            'options' => 'array',
            'summary' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
