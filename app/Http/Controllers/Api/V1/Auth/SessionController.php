<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(private AuthenticationService $authentication) {}

    /**
     * Start an authenticated session, or a pending two-factor challenge (`two_factor: true`).
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $twoFactor = $this->authentication->attempt($request->validated(), $request);

        return response()->json(['two_factor' => $twoFactor]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->authentication->logout($request);

        return response()->json(status: 204);
    }
}
