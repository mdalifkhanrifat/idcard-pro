<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For what only a person does for themself: an integration account's token must not be able to make itself more
 * tokens, or set a password or two-step sign-in, whatever its role allows.
 */
class EnsurePerson
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user instanceof User && $user->isIntegration(), Response::HTTP_FORBIDDEN, __('integrations.person_only'));

        return $next($request);
    }
}
