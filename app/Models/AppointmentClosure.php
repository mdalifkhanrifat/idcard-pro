<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AppointmentClosureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Days no appointment is taken (5.3): a holiday or a closure of one place, or of every place.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int|null $location_id null: every place
 * @property Carbon $date_from the organization's day, whole
 * @property Carbon $date_to the organization's day, whole
 * @property bool $yearly comes back every year on the same dates, from date_from's year on
 * @property string|null $reason
 * @property string|null $reason_local
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AppointmentLocation|null $location
 */
#[Fillable(['location_id', 'date_from', 'date_to', 'yearly', 'reason', 'reason_local'])]
class AppointmentClosure extends Model
{
    /** @use HasFactory<AppointmentClosureFactory> */
    use BelongsToTenant, HasFactory, HasUlids, LogsActivity;

    /**
     * As the table's default, so a new closure answers with it before it is read again.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'yearly' => false,
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
     * @return BelongsTo<AppointmentLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(AppointmentLocation::class, 'location_id');
    }

    /**
     * Whether the closure covers a day (Y-m-d, the organization's): within its dates, or — yearly — on the same
     * month and day as they are (across the new year too), from its first year on.
     */
    public function covers(string $day): bool
    {
        $from = $this->date_from->toDateString();
        $to = $this->date_to->toDateString();
        if (! $this->yearly) {
            return $day >= $from && $day <= $to;
        }
        if ($day < $from) {
            return false;
        }
        [$monthDay, $fromMonthDay, $toMonthDay] = [substr($day, 5), substr($from, 5), substr($to, 5)];

        return $fromMonthDay <= $toMonthDay
            ? $monthDay >= $fromMonthDay && $monthDay <= $toMonthDay
            : $monthDay >= $fromMonthDay || $monthDay <= $toMonthDay;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
            'yearly' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['location_id', 'date_from', 'date_to', 'yearly', 'reason', 'reason_local'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('appointment_closures');
    }
}
