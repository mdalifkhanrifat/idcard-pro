<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenants\UpdateTenantSettingsRequest;
use App\Http\Resources\TenantResource;
use App\Services\Cards\CardNumberFormat;
use App\Services\Cards\CardNumberService;
use App\Services\Tenants\TenantService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /**
     * Preview the next card number for a format and card type code (nothing is reserved).
     */
    public function cardNumberPreview(Request $request, CardNumberService $numbers): JsonResponse
    {
        Gate::authorize('settings.view');

        $input = $request->validate([
            'format' => ['sometimes', 'string', 'max:60', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && ($problem = CardNumberFormat::problem($value)) !== null) {
                    $fail($problem);
                }
            }],
            'code' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-_]*$/'],
        ]);

        return response()->json(['data' => ['number' => $numbers->preview($input['code'] ?? 'EMP', $input['format'] ?? null)]]);
    }
}
