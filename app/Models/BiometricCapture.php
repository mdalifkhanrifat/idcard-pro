<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BiometricCaptureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One capture of a position (config/biometrics.php) of a cardholder or of one of their family members,
 * or a note that the position cannot be captured. Image and template are encrypted files read through
 * BiometricVault; the activity log records only what was captured, never the data itself.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $cardholder_id
 * @property int|null $family_member_id
 * @property string $modality
 * @property string $position
 * @property string $status
 * @property string|null $unavailable_reason
 * @property string|null $note
 * @property string|null $driver
 * @property array<string, string>|null $device
 * @property string|null $disk
 * @property string|null $image_path
 * @property string|null $image_format
 * @property int|null $image_width
 * @property int|null $image_height
 * @property int|null $image_dpi
 * @property string|null $template_path
 * @property string|null $template_format
 * @property int|null $quality
 * @property bool $low_quality
 * @property int $attempts
 * @property int|null $captured_by
 * @property Carbon $captured_at
 * @property Carbon|null $replaced_at
 * @property string|null $current_key
 * @property-read Cardholder $cardholder
 * @property-read FamilyMember|null $familyMember
 * @property-read User|null $capturer
 */
#[Fillable([
    'cardholder_id', 'family_member_id', 'modality', 'position', 'status', 'unavailable_reason', 'note', 'driver',
    'device', 'disk', 'image_path', 'image_format', 'image_width', 'image_height', 'image_dpi', 'template_path',
    'template_format', 'quality', 'low_quality', 'attempts', 'captured_by', 'captured_at', 'replaced_at', 'current_key',
])]
class BiometricCapture extends Model
{
    /** @use HasFactory<BiometricCaptureFactory> */
    use BelongsToTenant, HasFactory, HasUlids, LogsActivity;

    public const STATUS_CAPTURED = 'captured';

    public const STATUS_UNAVAILABLE = 'unavailable';

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
            'device' => 'array',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'image_dpi' => 'integer',
            'quality' => 'integer',
            'low_quality' => 'boolean',
            'attempts' => 'integer',
            'captured_at' => 'datetime',
            'replaced_at' => 'datetime',
        ];
    }

    /**
     * Key that makes "one current capture per position of a holder" a database rule.
     */
    public static function currentKey(int $cardholderId, ?int $familyMemberId, string $modality, string $position): string
    {
        return Card::holderKey($cardholderId, $familyMemberId).":{$modality}:{$position}";
    }

    /**
     * @return BelongsTo<Cardholder, $this>
     */
    public function cardholder(): BelongsTo
    {
        return $this->belongsTo(Cardholder::class);
    }

    /**
     * @return BelongsTo<FamilyMember, $this>
     */
    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function capturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    /**
     * Captures not replaced by a newer one.
     *
     * @param  Builder<BiometricCapture>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('replaced_at');
    }

    public function isCaptured(): bool
    {
        return $this->status === self::STATUS_CAPTURED;
    }

    public function getActivitylogOptions(): LogOptions
    {
        // What was captured and how well, never the image, template or file locations
        return LogOptions::defaults()
            ->logOnly(['cardholder_id', 'family_member_id', 'modality', 'position', 'status', 'unavailable_reason', 'driver', 'quality', 'low_quality', 'replaced_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('biometrics');
    }
}
