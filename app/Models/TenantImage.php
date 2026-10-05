<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An image in the organization's library (logos, backgrounds), linked from card template elements.
 * The file never changes after upload.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $name
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $width pixels
 * @property int $height pixels
 * @property int $size bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $templates_count number of templates using it, when selected
 */
#[Fillable(['name', 'disk', 'path', 'mime_type', 'width', 'height', 'size'])]
class TenantImage extends Model
{
    /** @use HasFactory<TenantImageFactory> */
    use BelongsToTenant, HasFactory, HasUlids, LogsActivity;

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
            'width' => 'integer',
            'height' => 'integer',
            'size' => 'integer',
            'templates_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<CardTemplateElement, $this>
     */
    public function elements(): HasMany
    {
        return $this->hasMany(CardTemplateElement::class, 'library_image_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'path'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('images');
    }
}
