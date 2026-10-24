<?php

namespace App\Http\Middleware;

use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Services\Appointments\AppointmentSettings;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public booking link's organization (5.6), by its slug in the address: only an active one that has
 * appointments on and books through the public link; any other answers 404 alike, so the link says nothing of why.
 * Its answers are never kept by a cache or shown by a search engine.
 */
class ResolvePublicBookingTenant
{
    public function __construct(
        private TenantContext $tenantContext,
        private TenantRepositoryInterface $tenants,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenants->findBySlug((string) $request->route('organization'));
        abort_if($tenant === null || ! AppointmentSettings::opensPublicly($tenant), Response::HTTP_NOT_FOUND, __('appointments.public.unavailable'));

        $this->tenantContext->set($tenant);
        $request->route()?->forgetParameter('organization');

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
