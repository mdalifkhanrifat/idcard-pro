<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CardholderFileFactory;
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
 * A stored file of a cardholder: photo, signature or document (config/cardholder_files.php).
 * Files with a family member belong to that member (their own photo and signature).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $cardholder_id
 * @property int|null $family_member_id
 * @property string $collection
 * @property string|null $title
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property string $checksum
 * @property int|null $uploaded_by
 * @property Carbon|null $replaced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Cardholder $cardholder
 * @property-read FamilyMember|null $familyMember
 * @property-read User|null $uploader
 */
#[Fillable([
    'cardholder_id', 'family_member_id', 'collection', 'title', 'disk', 'path', 'original_name', 'mime_type',
    'size', 'width', 'height', 'checksum', 'uploaded_by', 'replaced_at',
])]
class CardholderFile extends Model
{
    /** @use HasFactory<CardholderFileFactory> */
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
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'replaced_at' => 'datetime',
        ];
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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Files that have not been replaced by a newer upload.
     *
     * @param  Builder<CardholderFile>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('replaced_at');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['cardholder_id', 'family_member_id', 'collection', 'title', 'original_name', 'replaced_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('cardholder_files');
    }
}
