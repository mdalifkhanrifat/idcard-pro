<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantFontFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One font file (a weight and style of a family) uploaded by an organization for its card templates.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $family
 * @property int $weight
 * @property string $style normal or italic
 * @property string $format truetype, opentype, woff or woff2
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property int $size bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['family', 'weight', 'style', 'format', 'disk', 'path', 'original_name', 'size'])]
class TenantFont extends Model
{
    /** @use HasFactory<TenantFontFactory> */
    use BelongsToTenant, HasFactory, HasUlids, LogsActivity;

    public const STYLES = ['normal', 'italic'];

    public const WEIGHTS = [100, 200, 300, 400, 500, 600, 700, 800, 900];

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
            'weight' => 'integer',
            'size' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['family', 'weight', 'style', 'original_name'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('fonts');
    }
}
