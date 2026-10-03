<?php

namespace App\Models;

use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\FamilyMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A family member of a cardholder (may later receive a family card).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $cardholder_id
 * @property FamilyRelation $relation
 * @property string $name
 * @property string|null $name_local
 * @property Gender|null $gender
 * @property Carbon|null $date_of_birth
 * @property string|null $phone
 * @property int|null $blood_group_id
 * @property string|null $national_id
 * @property string|null $passport_no
 * @property string|null $identification_mark
 * @property string|null $card_no
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Cardholder $cardholder
 * @property-read MasterDataItem|null $bloodGroup
 */
#[Fillable([
    'cardholder_id', 'relation', 'name', 'name_local', 'gender', 'date_of_birth', 'phone', 'blood_group_id',
    'national_id', 'passport_no', 'identification_mark', 'card_no', 'sort_order',
])]
class FamilyMember extends Model
{
    /** @use HasFactory<FamilyMemberFactory> */
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
            'relation' => FamilyRelation::class,
            'gender' => Gender::class,
            'date_of_birth' => 'date',
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

    /**
     * @return BelongsTo<MasterDataItem, $this>
     */
    public function bloodGroup(): BelongsTo
    {
        return $this->belongsTo(MasterDataItem::class, 'blood_group_id');
    }

    /**
     * Personal identifiers and contact details are left out of the audit trail.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['cardholder_id', 'relation', 'name', 'card_no'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('family_members');
    }
}
