<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Actions\Tenancy\ProvisionTenant;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Platform-level tenant (organisation) management.
 */
class TenantController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return TenantResource::collection(
            QueryBuilder::for(Tenant::class)
                ->allowedFilters(AllowedFilter::partial('name'), AllowedFilter::exact('status'), AllowedFilter::exact('slug'))
                ->allowedSorts('name', 'created_at')
                ->defaultSort('name')
                ->withCount('users')
                ->paginate(min((int) $request->integer('per_page', 15), 100))
        );
    }

    public function store(Request $request, ProvisionTenant $provision): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'alpha_dash', 'max:63', 'unique:tenants,slug'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'timezone'],
            'locale' => ['nullable', 'in:en,bn'],
            'owner.name' => ['required', 'string', 'max:120'],
            'owner.email' => ['required', 'email', 'max:255'],
            'owner.password' => ['required', Password::defaults()],
        ]);

        $tenant = $provision->handle(
            [...collect($data)->except('owner')->all(), 'slug' => strtolower($data['slug'])],
            [...$data['owner'], 'email' => strtolower($data['owner']['email'])],
        );

        return (new TenantResource($tenant->loadCount('users')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Tenant $tenant): TenantResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', new Enum(TenantStatus::class)],
        ]);

        $tenant->update($data);

        return new TenantResource($tenant->loadCount('users'));
    }
}
