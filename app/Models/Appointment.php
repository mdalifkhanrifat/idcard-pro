<?php

namespace App\Models;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An appointment (5.4): a cardholder, or a family member of theirs, booked at a place for a kind of visit on a
 * day of the organization — at a time when times are booked. Never deleted: cancelled instead.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $number the organization's own format (settings appointments.number_format)
 * @property int $location_id
 * @property string $service a kind of visit's key
 * @property int $cardholder_id
 * @property int|null $family_member_id
 * @property Carbon $date the organization's day
 * @property Carbon|null $starts_at UTC; null when a day is booked
 * @property Carbon|null $ends_at UTC
 * @property AppointmentStatus $status
 * @property AppointmentSource $source
 * @property int|null $booked_by_id
 * @property string|null $note
 * @property string|null $cancel_reason
 * @property Carbon|null $status_changed_at
 * @property int|null $status_changed_by_id
 * @property AppointmentSource|null $status_changed_via which way the last step was taken (null before any)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AppointmentLocation $location
 * @property-read Cardholder $cardholder
 * @property-read FamilyMember|null $familyMember
 * @property-read User|null $bookedBy
 * @property-read User|null $statusChangedBy
 */
#[Fillable(['number', 'location_id', 'service', 'cardholder_id', 'family_member_id', 'date', 'starts_at', 'ends_at', 'status', 'source', 'booked_by_id', 'note', 'cancel_reason', 'status_changed_at', 'status_changed_by_id', 'status_changed_via'])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
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
     * @return BelongsTo<AppointmentLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(AppointmentLocation::class, 'location_id');
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
    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => AppointmentStatus::class,
            'source' => AppointmentSource::class,
            'status_changed_at' => 'datetime',
            'status_changed_via' => AppointmentSource::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'location_id', 'service', 'cardholder_id', 'family_member_id', 'date', 'starts_at', 'ends_at', 'status', 'note', 'cancel_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('appointments');
    }
}
