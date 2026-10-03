<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated user's tenant to the request.
 *
 * Platform administrators have no tenant of their own and may act inside a
 * tenant by sending its slug in the `X-Tenant` header.
 */
class ResolveTenant
{
    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($user->is_platform_admin && $user->tenant_id === null) {
            $slug = $request->header('X-Tenant');
            $tenant = $slug ? Tenant::query()->where('slug', $slug)->first() : null;
            abort_if($slug && $tenant === null, Response::HTTP_NOT_FOUND, __('tenancy.not_found'));

            $this->tenantContext->set($tenant);

            return $next($request);
        }

        $tenant = $user->tenant;
        abort_if($tenant === null || ! $tenant->isActive() || ! $user->isActive(), Response::HTTP_FORBIDDEN, __('auth.inactive'));

        $this->tenantContext->set($tenant);

        return $next($request);
    }
}
