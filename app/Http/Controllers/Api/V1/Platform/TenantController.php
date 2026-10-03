<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenants\ListTenantsRequest;
use App\Http\Requests\Tenants\StoreTenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Services\Tenants\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;

/**
 * Platform-level organization management.
 */
class TenantController extends Controller
{
    public function __construct(private TenantService $tenants) {}

    /**
     * Filters: filter[name|slug|status]. Sorts: name, created_at.
     */
    public function index(ListTenantsRequest $request): AnonymousResourceCollection
    {
        return TenantResource::collection($this->tenants->list($request->toFilter()));
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tenant = $this->tenants->provision(collect($data)->except('owner')->all(), $data['owner']);

        return (new TenantResource($tenant->loadCount('users')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Tenant $tenant): TenantResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', new Enum(TenantStatus::class)],
        ]);

        return new TenantResource($this->tenants->update($tenant, $data)->loadCount('users'));
    }
}
