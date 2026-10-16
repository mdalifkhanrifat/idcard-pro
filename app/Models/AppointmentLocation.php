<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AppointmentLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A place people come to for an appointment (5.3): a branch, an office or a counter.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $name_local
 * @property string|null $address
 * @property int $slot_capacity people served at once in one time slot
 * @property int $day_capacity people served in one day (day scheduling)
 * @property array<int, string>|null $services kinds of visit it takes; null: every kind
 * @property array<string, array<int, array{0: string, 1: string}>> $hours weekday => opening periods
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'name_local', 'address', 'slot_capacity', 'day_capacity', 'services', 'hours', 'is_active', 'sort_order'])]
class AppointmentLocation extends Model
{
    /** @use HasFactory<AppointmentLocationFactory> */
    use BelongsToTenant, HasFactory, HasUlids, LogsActivity;

    /**
     * As the table's defaults, so a new place answers with them before it is read again.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
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
            'services' => 'array',
            'hours' => 'array',
            'is_active' => 'boolean',
            'slot_capacity' => 'integer',
            'day_capacity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'name_local', 'address', 'slot_capacity', 'day_capacity', 'services', 'hours', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('appointment_locations');
    }
}
