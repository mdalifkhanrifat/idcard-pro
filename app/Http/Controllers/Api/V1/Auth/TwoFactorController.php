<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Enrol, confirm and disable TOTP two-factor authentication for the current user.
 */
class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorAuthenticator $twoFactor) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $user = $request->user();

        $secret = $this->twoFactor->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return response()->json([
            'data' => [
                'secret' => $secret,
                'qr_svg' => $this->twoFactor->qrCodeSvg($user, $secret),
            ],
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);
        $user = $request->user();

        if ($user->two_factor_secret === null || ! $this->twoFactor->verify($user->two_factor_secret, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => __('auth.two_factor_invalid')]);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return response()->json(status: 204);
    }
}
