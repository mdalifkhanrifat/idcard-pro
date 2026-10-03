<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\AuthenticateUser;
use App\Enums\LoginEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    public const TWO_FACTOR_SESSION_KEY = 'auth.two_factor';

    /**
     * Start an authenticated session, or a pending two-factor challenge.
     */
    public function store(LoginRequest $request, AuthenticateUser $authenticate): JsonResponse
    {
        $user = $authenticate->handle($request->validated(), $request);

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put(self::TWO_FACTOR_SESSION_KEY, [
                'user_id' => $user->id,
                'remember' => $request->boolean('remember'),
                'expires_at' => now()->addMinutes(5)->timestamp,
            ]);
            LoginEvent::record(LoginEventType::TwoFactorRequired, $request, $user);

            return response()->json(['two_factor' => true]);
        }

        self::completeLogin($request, $user, $request->boolean('remember'));

        return response()->json(['two_factor' => false]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user !== null) {
            LoginEvent::record(LoginEventType::LoggedOut, $request, $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(status: 204);
    }

    public static function completeLogin(Request $request, User $user, bool $remember): void
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->forget(self::TWO_FACTOR_SESSION_KEY);

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        LoginEvent::record(LoginEventType::Succeeded, $request, $user);
    }
}
