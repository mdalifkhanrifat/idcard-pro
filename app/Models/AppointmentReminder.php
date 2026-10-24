<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A reminder sent before an appointment (5.7c): which, how many hours before, and the moment it was due.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $appointment_id
 * @property int $hours
 * @property Carbon $due_at UTC
 * @property Carbon|null $created_at
 */
#[Fillable(['tenant_id', 'appointment_id', 'hours', 'due_at'])]
class AppointmentReminder extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }
}
