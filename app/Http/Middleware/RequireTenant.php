<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects tenant-scoped endpoints when no tenant is bound (e.g. a platform admin without `X-Tenant`).
 */
class RequireTenant
{
    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->tenantContext->has(), Response::HTTP_CONFLICT, __('tenancy.required'));

        return $next($request);
    }
}
