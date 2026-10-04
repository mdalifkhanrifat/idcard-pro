<?php

namespace App\Models;

use App\Enums\TemplateAudience;
use App\Enums\TemplateOrientation;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CardTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An ID card layout (legacy "template"); its sections are designed in the template designer.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $name
 * @property string $code
 * @property int|null $card_type_id
 * @property TemplateAudience $audience
 * @property TemplateOrientation $orientation
 * @property string $width
 * @property string $height
 * @property string $size_unit
 * @property int|null $authority_type_id
 * @property int|null $validity_months
 * @property bool $is_double_sided
 * @property bool $is_active
 * @property int $version
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MasterDataItem|null $cardType
 * @property-read MasterDataItem|null $authorityType
 * @property-read Collection<int, CardTemplateElement> $elements
 */
#[Fillable([
    'name', 'code', 'card_type_id', 'audience', 'orientation', 'width', 'height', 'size_unit',
    'authority_type_id', 'validity_months', 'is_double_sided', 'is_active', 'version', 'created_by',
])]
class CardTemplate extends Model
{
    /** @use HasFactory<CardTemplateFactory> */
    use BelongsToTenant, HasFactory, HasUlids, LogsActivity;

    /**
     * CR80, the common ID card size in millimetres.
     */
    public const CR80 = ['width' => 85.6, 'height' => 54.0];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'audience' => 'cardholder',
        'orientation' => 'horizontal',
        'size_unit' => 'mm',
        'is_double_sided' => true,
        'is_active' => true,
        'version' => 1,
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
            'audience' => TemplateAudience::class,
            'orientation' => TemplateOrientation::class,
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'validity_months' => 'integer',
            'is_double_sided' => 'boolean',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MasterDataItem, $this>
     */
    public function cardType(): BelongsTo
    {
        return $this->belongsTo(MasterDataItem::class, 'card_type_id');
    }

    /**
     * @return BelongsTo<MasterDataItem, $this>
     */
    public function authorityType(): BelongsTo
    {
        return $this->belongsTo(MasterDataItem::class, 'authority_type_id');
    }

    /**
     * Designer elements of both sides, bottom layer first.
     *
     * @return HasMany<CardTemplateElement, $this>
     */
    public function elements(): HasMany
    {
        return $this->hasMany(CardTemplateElement::class)->orderBy('side')->orderBy('z_index')->orderBy('id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'card_type_id', 'audience', 'orientation', 'width', 'height', 'size_unit', 'authority_type_id', 'validity_months', 'is_active', 'version'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('card_templates');
    }
}
