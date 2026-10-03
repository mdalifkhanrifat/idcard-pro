<?php

namespace App\Models;

use App\Enums\CardholderStatus;
use App\Enums\EmploymentType;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CardholderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A person who can be issued an ID card (employee, contractor, visitor, student ...).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $full_name
 * @property string|null $full_name_local
 * @property Carbon|null $date_of_birth
 * @property Gender|null $gender
 * @property MaritalStatus|null $marital_status
 * @property string|null $nationality
 * @property string|null $national_id
 * @property string|null $passport_no
 * @property string|null $email
 * @property string|null $personal_phone
 * @property string|null $emergency_phone
 * @property string|null $present_address
 * @property string|null $permanent_address
 * @property string|null $postal_code
 * @property string|null $height
 * @property string|null $weight
 * @property string|null $eye_color
 * @property string|null $complexion
 * @property string|null $identification_mark
 * @property string|null $employee_no
 * @property string|null $secondary_no
 * @property EmploymentType $employment_type
 * @property Carbon|null $joining_date
 * @property Carbon|null $retirement_date
 * @property string|null $retired_id_no
 * @property string|null $posting
 * @property string|null $previous_office
 * @property string|null $company_name
 * @property string|null $company_address
 * @property string|null $company_phone
 * @property int|null $blood_group_id
 * @property int|null $religion_id
 * @property int|null $region_id
 * @property int|null $sub_region_id
 * @property int|null $department_id
 * @property int|null $unit_id
 * @property int|null $rank_id
 * @property int|null $designation_id
 * @property int|null $branch_id
 * @property int|null $trade_id
 * @property int|null $appointment_id
 * @property CardholderStatus $status
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 * @property-read CardholderFile|null $photo
 * @property-read CardholderFile|null $signature
 * @property-read Collection<int, CardholderFile> $files
 * @property-read Collection<int, FamilyMember> $familyMembers
 * @property-read Collection<int, Qualification> $qualifications
 */
#[Fillable([
    'full_name', 'full_name_local', 'date_of_birth', 'gender', 'marital_status', 'nationality', 'national_id', 'passport_no',
    'email', 'personal_phone', 'emergency_phone', 'present_address', 'permanent_address', 'postal_code',
    'height', 'weight', 'eye_color', 'complexion', 'identification_mark',
    'employee_no', 'secondary_no', 'employment_type', 'joining_date', 'retirement_date', 'retired_id_no', 'posting', 'previous_office',
    'company_name', 'company_address', 'company_phone',
    'blood_group_id', 'religion_id', 'region_id', 'sub_region_id',
    'department_id', 'unit_id', 'rank_id', 'designation_id', 'branch_id', 'trade_id', 'appointment_id',
    'status',
])]
class Cardholder extends Model
{
    /** @use HasFactory<CardholderFactory> */
    use BelongsToTenant, HasFactory, HasUlids, LogsActivity;

    /**
     * Master data references: column => master data type (config/master_data.php).
     *
     * @var array<string, string>
     */
    public const LOOKUPS = [
        'blood_group_id' => 'blood_group',
        'religion_id' => 'religion',
        'region_id' => 'region',
        'sub_region_id' => 'sub_region',
        'department_id' => 'department',
        'unit_id' => 'unit',
        'rank_id' => 'rank',
        'designation_id' => 'designation',
        'branch_id' => 'branch',
        'trade_id' => 'trade',
        'appointment_id' => 'appointment',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'employment_type' => 'staff',
        'status' => 'active',
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
     * Relation names for every master data reference (e.g. "department").
     *
     * @return array<int, string>
     */
    public static function lookupRelations(): array
    {
        return array_map(Str::camel(...), array_values(self::LOOKUPS));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'joining_date' => 'date',
            'retirement_date' => 'date',
            'gender' => Gender::class,
            'marital_status' => MaritalStatus::class,
            'employment_type' => EmploymentType::class,
            'status' => CardholderStatus::class,
        ];
    }

    /**
     * @return BelongsTo<MasterDataItem, $this>
     */
    public function bloodGroup(): BelongsTo
    {
        return $this->belongsTo(MasterDataItem::class, 'blood_group_id');
    }

    /**
     * @return BelongsTo<MasterDataItem, $this>
     */
    public function religion(): BelongsTo
    {
        return $this->belongsTo(MasterDataItem::class, 'religion_id');
    }

    /**
     * @return BelongsTo<MasterDataItem, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(MasterDataItem::class, 'region_id');
    }

    /**
     * @return BelongsTo<MasterDataItem, $this>
     */
    public function subRegion(): BelongsTo
    {
        return $this->belongsTo(MasterDataItem::class, 'sub_region_id');
    }

    /**
     * @return BelongsTo<MasterDataItem, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(MasterDataItem::class, 'department_id');
    }

    /**
     * @return BelongsTo<MasterDataItem, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(MasterDataItem::class, 'unit_id');
    }

    /**
     * @return BelongsTo<MasterDataItem, $this>
     */
    public function rank(): BelongsTo
    {
        return $this->belongsTo(MasterDataItem::class, 'rank_id');
    }

    /**
     * @return BelongsTo<MasterDataItem, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(MasterDataItem::class, 'designation_id');
    }

    /**
     * @return BelongsTo<MasterDataItem, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(MasterDataItem::class, 'branch_id');
    }

    /**
     * @return BelongsTo<MasterDataItem, $this>
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(MasterDataItem::class, 'trade_id');
    }

    /**
     * @return BelongsTo<MasterDataItem, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(MasterDataItem::class, 'appointment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Every stored file, including replaced versions.
     *
     * @return HasMany<CardholderFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(CardholderFile::class);
    }

    /**
     * @return HasMany<FamilyMember, $this>
     */
    public function familyMembers(): HasMany
    {
        return $this->hasMany(FamilyMember::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<Qualification, $this>
     */
    public function qualifications(): HasMany
    {
        return $this->hasMany(Qualification::class)->orderBy('kind')->orderBy('sort_order');
    }

    /**
     * @return HasOne<CardholderFile, $this>
     */
    public function photo(): HasOne
    {
        return $this->currentFile('photo');
    }

    /**
     * @return HasOne<CardholderFile, $this>
     */
    public function signature(): HasOne
    {
        return $this->currentFile('signature');
    }

    /**
     * @return HasOne<CardholderFile, $this>
     */
    private function currentFile(string $collection): HasOne
    {
        return $this->hasOne(CardholderFile::class)->ofMany(
            ['id' => 'max'],
            fn (Builder $query) => $query->where('collection', $collection)->whereNull('replaced_at'),
        );
    }

    /**
     * Personal identifiers and contact details are deliberately left out of the audit trail.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'full_name', 'employee_no', 'secondary_no', 'employment_type', 'status',
                'department_id', 'unit_id', 'rank_id', 'designation_id', 'joining_date', 'retirement_date',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('cardholders');
    }
}
