<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->forceFill([
            'password' => $request->input('password'),
            'password_changed_at' => now(),
        ])->save();

        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $guard->logoutOtherDevices($request->input('password'));

        return response()->json(['message' => __('auth.password_changed')]);
    }
}
