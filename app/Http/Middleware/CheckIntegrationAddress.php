<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Audit\AuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * An integration account that names its addresses works only from them. A refusal is on the sign-in history, once in
 * a while per address: a token in the wrong hands must show there, without one line per request filling it.
 */
class CheckIntegrationAddress
{
    private const NOTE_EVERY_SECONDS = 300;

    public function __construct(private AuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isIntegration() && filled($user->allowed_ips) && ! IpUtils::checkIp((string) $request->ip(), $user->allowed_ips)) {
            RateLimiter::attempt(
                "integration-address:{$user->id}:{$request->ip()}",
                1,
                fn () => $this->audit->recordRefusedAddress($request, $user),
                self::NOTE_EVERY_SECONDS,
            );

            abort(Response::HTTP_FORBIDDEN, __('integrations.address_refused'));
        }

        return $next($request);
    }
}
