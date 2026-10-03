<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SignatoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An official whose signature is printed on ID cards (legacy "authorized signature").
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $name_local
 * @property string $designation
 * @property string|null $designation_local
 * @property int|null $authority_type_id
 * @property string|null $signature_disk
 * @property string|null $signature_path
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MasterDataItem|null $authorityType
 */
#[Fillable(['name', 'name_local', 'designation', 'designation_local', 'authority_type_id', 'signature_disk', 'signature_path', 'effective_from', 'effective_to', 'is_active'])]
class Signatory extends Model
{
    /** @use HasFactory<SignatoryFactory> */
    use BelongsToTenant, HasFactory, HasUlids, LogsActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => false,
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
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<MasterDataItem, $this>
     */
    public function authorityType(): BelongsTo
    {
        return $this->belongsTo(MasterDataItem::class, 'authority_type_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'designation', 'authority_type_id', 'signature_path', 'effective_from', 'effective_to', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('signatories');
    }
}
