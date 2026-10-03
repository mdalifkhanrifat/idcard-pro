<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\LoginEventType;
use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TwoFactorChallengeController extends Controller
{
    public function store(Request $request, TwoFactorAuthenticator $twoFactor): JsonResponse
    {
        $request->validate([
            'code' => ['nullable', 'string', 'max:16', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        $pending = $request->session()->get(SessionController::TWO_FACTOR_SESSION_KEY);

        if (! is_array($pending) || $pending['expires_at'] < now()->timestamp) {
            $request->session()->forget(SessionController::TWO_FACTOR_SESSION_KEY);

            throw ValidationException::withMessages(['code' => __('auth.two_factor_expired')]);
        }

        $user = User::query()->withoutGlobalScopes()->findOrFail($pending['user_id']);

        if (! $twoFactor->verifyForUser($user, $request->input('code'), $request->input('recovery_code'))) {
            LoginEvent::record(LoginEventType::TwoFactorFailed, $request, $user);

            throw ValidationException::withMessages(['code' => __('auth.two_factor_invalid')]);
        }

        SessionController::completeLogin($request, $user, (bool) $pending['remember']);

        return response()->json(['two_factor' => false]);
    }
}
