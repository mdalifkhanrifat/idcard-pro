<?php

namespace App\Models;

use App\Enums\QualificationKind;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\QualificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An education record or training course of a cardholder.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $cardholder_id
 * @property QualificationKind $kind
 * @property string $title exam (education) or course name
 * @property string|null $institution board / university / training institute
 * @property int|null $year passing or completion year
 * @property string|null $subject group or subject
 * @property string|null $degree
 * @property string|null $result GPA, class or grade
 * @property string|null $certificate_no
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Cardholder $cardholder
 */
#[Fillable(['cardholder_id', 'kind', 'title', 'institution', 'year', 'subject', 'degree', 'result', 'certificate_no', 'sort_order'])]
class Qualification extends Model
{
    /** @use HasFactory<QualificationFactory> */
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
            'kind' => QualificationKind::class,
            'year' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Cardholder, $this>
     */
    public function cardholder(): BelongsTo
    {
        return $this->belongsTo(Cardholder::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['cardholder_id', 'kind', 'title', 'institution', 'year', 'result'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('qualifications');
    }
}
