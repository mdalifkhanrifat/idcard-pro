<?php

namespace App\Models;

use App\Enums\LoginEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One sign-in event (success, failure, lock...), kept for the audit log.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $user_id
 * @property string|null $identifier the email or username tried
 * @property LoginEventType $event
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property-read User|null $user
 */
#[Fillable(['tenant_id', 'user_id', 'identifier', 'event', 'ip_address', 'user_agent'])]
class LoginEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => LoginEventType::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }
}
