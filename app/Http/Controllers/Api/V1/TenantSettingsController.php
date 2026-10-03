<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Support\Tenancy\TenantContext;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TenantSettingsController extends Controller
{
    public function __construct(private TenantContext $tenantContext) {}

    public function show(): TenantResource
    {
        Gate::authorize('settings.view');

        return new TenantResource($this->tenantContext->get());
    }

    public function update(Request $request): TenantResource
    {
        Gate::authorize('settings.update');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'locale' => ['required', Rule::in(['en', 'bn'])],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $tenant = $this->tenantContext->get();

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store("tenants/{$tenant->public_id}/branding", 'public');
        }

        $tenant->update(collect($data)->except('logo')->all());

        return new TenantResource($tenant->refresh());
    }
}
