<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Http\Resources\UserResource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CurrentUserController extends Controller
{
    /**
     * The signed-in user with tenant, roles and effective permissions.
     */
    public function show(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $user = $request->user()->load('roles');
        $tenant = $tenantContext->get();

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'tenant' => $tenant ? new TenantResource($tenant) : null,
                'permissions' => $user->is_platform_admin ? ['*'] : $user->getAllPermissions()->pluck('name'),
            ],
        ]);
    }

    public function update(Request $request): UserResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', Rule::in(['en', 'bn'])],
        ]);

        $request->user()->update($data);

        return new UserResource($request->user()->load('roles'));
    }
}
