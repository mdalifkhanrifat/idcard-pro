<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenants\UpdateTenantSettingsRequest;
use App\Http\Resources\TenantResource;
use App\Services\Tenants\TenantService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

class TenantSettingsController extends Controller
{
    public function __construct(
        private TenantService $tenants,
        private TenantContext $tenantContext,
    ) {}

    public function show(): TenantResource
    {
        Gate::authorize('settings.view');

        return new TenantResource($this->tenantContext->get());
    }

    public function update(UpdateTenantSettingsRequest $request): TenantResource
    {
        $tenant = $this->tenants->updateSettings(
            $this->tenantContext->get(),
            collect($request->validated())->except('logo')->all(),
            $request->file('logo'),
        );

        return new TenantResource($tenant);
    }
}
