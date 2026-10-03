<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\MasterDataItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An entry in one of the tenant's reference lists (department, rank, region, ...).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $type
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $name_local
 * @property string|null $code
 * @property int $sort_order
 * @property bool $is_active
 * @property-read MasterDataItem|null $parent
 */
#[Fillable(['type', 'parent_id', 'name', 'name_local', 'code', 'sort_order', 'is_active'])]
class MasterDataItem extends Model
{
    /** @use HasFactory<MasterDataItemFactory> */
    use BelongsToTenant, HasFactory, HasUlids, LogsActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
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
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<MasterDataItem, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<MasterDataItem, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @param  Builder<MasterDataItem>  $query
     */
    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'name', 'name_local', 'code', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('master_data');
    }
}
