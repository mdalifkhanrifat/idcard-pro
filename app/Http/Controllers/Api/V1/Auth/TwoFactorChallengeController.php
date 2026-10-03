<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorChallengeController extends Controller
{
    public function store(Request $request, AuthenticationService $authentication): JsonResponse
    {
        $request->validate([
            'code' => ['nullable', 'string', 'max:16', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        $authentication->completeTwoFactor($request, $request->input('code'), $request->input('recovery_code'));

        return response()->json(['two_factor' => false]);
    }
}
