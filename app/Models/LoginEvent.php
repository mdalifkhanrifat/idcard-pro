<?php

namespace App\Models;

use App\Enums\LoginEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

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

    public static function record(LoginEventType $event, Request $request, ?User $user = null, ?Tenant $tenant = null, ?string $identifier = null): self
    {
        return self::create([
            'tenant_id' => $tenant->id ?? $user?->tenant_id,
            'user_id' => $user?->id,
            'identifier' => $identifier,
            'event' => $event,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
        ]);
    }
}
