<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\ListIntegrationsRequest;
use App\Http\Requests\Integrations\SaveIntegrationRequest;
use App\Http\Resources\IntegrationResource;
use App\Models\User;
use App\Services\Integrations\IntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class IntegrationController extends Controller
{
    public function __construct(private IntegrationService $integrations) {}

    /**
     * Filters: filter[search|status|role]. Sorts: name, created_at (prefix "-" for descending).
     */
    public function index(ListIntegrationsRequest $request): AnonymousResourceCollection
    {
        return IntegrationResource::collection($this->integrations->list($request->toFilter()));
    }

    public function store(SaveIntegrationRequest $request): JsonResponse
    {
        return (new IntegrationResource($this->integrations->create($request->validated(), $request->user())))
            ->response()->setStatusCode(201);
    }

    public function show(User $integration): IntegrationResource
    {
        Gate::authorize('integrations.manage');

        return new IntegrationResource($integration->load('roles'));
    }

    public function update(SaveIntegrationRequest $request, User $integration): IntegrationResource
    {
        return new IntegrationResource($this->integrations->update($integration, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, User $integration): JsonResponse
    {
        Gate::authorize('integrations.manage');

        $this->integrations->delete($integration, $request->user());

        return response()->json(status: 204);
    }
}
