<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\StoreIntegrationTokenRequest;
use App\Http\Resources\ApiTokenResource;
use App\Models\User;
use App\Services\Integrations\IntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class IntegrationTokenController extends Controller
{
    public function __construct(private IntegrationService $integrations) {}

    public function index(User $integration): AnonymousResourceCollection
    {
        Gate::authorize('integrations.manage');

        return ApiTokenResource::collection($this->integrations->tokens($integration));
    }

    /**
     * `name`, and `expires_in_days` (else the organization's usual length). The token itself is in this answer only:
     * it is kept hashed and cannot be shown again.
     */
    public function store(StoreIntegrationTokenRequest $request, User $integration): JsonResponse
    {
        $token = $this->integrations->issueToken($integration, $request->validated(), $request->user());

        return response()
            ->json(['data' => [...(new ApiTokenResource($token->accessToken))->resolve($request), 'plain_text_token' => $token->plainTextToken]], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function destroy(User $integration, int $token): JsonResponse
    {
        Gate::authorize('integrations.manage');

        $this->integrations->revokeToken($integration, $token);

        return response()->json(status: 204);
    }
}
