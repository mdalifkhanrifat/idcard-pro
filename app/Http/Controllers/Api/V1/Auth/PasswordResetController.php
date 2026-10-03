<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class PasswordResetController extends Controller
{
    public function __construct(private PasswordResetService $passwordReset) {}

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization' => ['nullable', 'string', 'max:63'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $this->passwordReset->sendResetLink($data['organization'] ?? null, $data['email']);

        return response()->json(['message' => __('auth.reset_link_sent')]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $this->passwordReset->reset($data['token'], $data['password'], $request);

        return response()->json(['message' => __('auth.password_reset_done')]);
    }
}
