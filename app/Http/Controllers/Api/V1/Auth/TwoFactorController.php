<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Account\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Enrol, confirm and disable TOTP two-factor authentication for the current user.
 */
class TwoFactorController extends Controller
{
    public function __construct(private AccountService $account) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        return response()->json(['data' => $this->account->startTwoFactor($request->user())]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);

        return response()->json(['data' => ['recovery_codes' => $this->account->confirmTwoFactor($request->user(), $request->input('code'))]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $this->account->disableTwoFactor($request->user());

        return response()->json(status: 204);
    }
}
