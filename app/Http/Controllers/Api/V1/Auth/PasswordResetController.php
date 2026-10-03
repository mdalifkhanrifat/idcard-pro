<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\LoginEventType;
use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Tenant-aware password reset. The same e-mail address may exist in several tenants,
 * so tokens are bound to a single user row and stored only as a SHA-256 hash.
 */
class PasswordResetController extends Controller
{
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization' => ['nullable', 'string', 'max:63'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $tenant = filled($data['organization'] ?? null)
            ? Tenant::query()->where('slug', strtolower($data['organization']))->first()
            : null;

        $user = User::query()->withoutGlobalScopes()
            ->where('email', strtolower($data['email']))
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id), fn ($q) => $q->whereNull('tenant_id'))
            ->first();

        if ($user !== null && $user->isActive() && (filled($data['organization'] ?? null) === ($tenant !== null))) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->insert([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(config('security.password.reset_token_minutes')),
                'created_at' => now(),
            ]);

            $user->notify(new ResetPasswordNotification($token));
        }

        return response()->json(['message' => __('auth.reset_link_sent')]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $row = DB::table('password_reset_tokens')
            ->where('token_hash', hash('sha256', $request->input('token')))
            ->where('expires_at', '>', now())
            ->first();

        if ($row === null) {
            throw ValidationException::withMessages(['token' => __('auth.reset_token_invalid')]);
        }

        $user = User::query()->withoutGlobalScopes()->findOrFail($row->user_id);
        $user->forceFill([
            'password' => $request->input('password'),
            'password_changed_at' => now(),
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'remember_token' => Str::random(60),
        ])->save();
        $user->tokens()->delete();

        DB::table('password_reset_tokens')->where('user_id', $user->id)->delete();
        LoginEvent::record(LoginEventType::PasswordReset, $request, $user);

        return response()->json(['message' => __('auth.password_reset_done')]);
    }
}
