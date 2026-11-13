<?php

namespace App\Models\Concerns;

use App\Support\Audit\AuditValues;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * The audit log of a record (spatie/laravel-activitylog), tidied before each row is written: a record created or
 * deleted keeps the fields that hold something, a private field (config audit.private) its change without its
 * values. A model with its own beforeActivityLogged() calls AuditValues::tidy() itself.
 *
 * @mixin Model
 */
trait AuditsChanges
{
    use LogsActivity;

    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        AuditValues::tidy($activity);
    }
}
