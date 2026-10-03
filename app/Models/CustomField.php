<?php

namespace App\Models;

use App\Enums\CustomFieldType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An organization-defined extra field on a record type (e.g. "Class" for students).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $entity
 * @property string $key
 * @property string $label
 * @property string|null $label_local
 * @property string|null $group
 * @property CustomFieldType $type
 * @property array<int, string>|null $options
 * @property bool $is_required
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable(['entity', 'key', 'label', 'label_local', 'group', 'type', 'options', 'is_required', 'is_active', 'sort_order'])]
class CustomField extends Model
{
    /** @use HasFactory<CustomFieldFactory> */
    use BelongsToTenant, HasFactory, HasUlids, LogsActivity;

    public const ENTITIES = ['cardholder'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
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
            'type' => CustomFieldType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['entity', 'key', 'label', 'type', 'is_required', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('custom_fields');
    }
}
