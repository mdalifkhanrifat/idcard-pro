<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appointments\UpdateAppointmentSettingsRequest;
use App\Http\Resources\AppointmentSettingsResource;
use App\Services\Appointments\AppointmentLocationService;
use App\Services\Tenants\TenantService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/**
 * The organization's appointment settings on their own, for the appointments settings page and integrations;
 * saved as a partial change the same way as the organization's settings (one audit entry per changed setting).
 */
class AppointmentSettingsController extends Controller
{
    public function __construct(
        private TenantService $tenants,
        private TenantContext $tenantContext,
        private AppointmentLocationService $locations,
    ) {}

    /**
     * For those who see appointments or may change the settings.
     */
    public function show(): AppointmentSettingsResource
    {
        abort_unless(Gate::any(['appointments.view', 'settings.update']), 403);

        return new AppointmentSettingsResource($this->tenantContext->get(), $this->locations->counts());
    }

    public function update(UpdateAppointmentSettingsRequest $request): AppointmentSettingsResource
    {
        $tenant = $this->tenants->updateAppointmentSettings($this->tenantContext->get(), (array) $request->validated('appointments'));

        return new AppointmentSettingsResource($tenant, $this->locations->counts());
    }
}
