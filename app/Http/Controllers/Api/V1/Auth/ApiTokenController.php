<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Personal access tokens for integrations (HR, access control, mobile apps).
 */
class ApiTokenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->tokens()->latest()->get()->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
                'created_at' => $token->created_at,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'expires_in_days' => ['nullable', 'integer', 'between:1,365'],
        ]);

        $token = $request->user()->createToken(
            $data['name'],
            ['*'],
            isset($data['expires_in_days']) ? now()->addDays($data['expires_in_days']) : null,
        );

        return response()->json(['data' => ['id' => $token->accessToken->id, 'plain_text_token' => $token->plainTextToken]], 201);
    }

    public function destroy(Request $request, int $tokenId): JsonResponse
    {
        $request->user()->tokens()->whereKey($tokenId)->delete();

        return response()->json(status: 204);
    }
}
