<?php

use App\Http\Middleware\CheckIntegrationAddress;
use App\Http\Middleware\EnsurePerson;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\RequirePublicBookingSession;
use App\Http\Middleware\RequireTenant;
use App\Http\Middleware\ResolvePublicBookingTenant;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetLocale;
use App\Support\Localization\LocalDigits;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->api(append: [SetLocale::class]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'tenant.required' => RequireTenant::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            'person' => EnsurePerson::class,
            'integration.address' => CheckIntegrationAddress::class,
            'public.booking' => ResolvePublicBookingTenant::class,
            'public.booking.session' => RequirePublicBookingSession::class,
        ]);

        // The tenant must be bound before route-model binding so that
        // {user}, {role}, ... can only resolve records of the current tenant.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        // A limit answers in the reader's language, so the language is settled first; after signing in, to know them
        $middleware->prependToPriorityList(ThrottleRequests::class, SetLocale::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Laravel's own words are in English only; the reader is told in their language, and when
        $exceptions->render(fn (ThrottleRequestsException $exception, Request $request) => response()->json(
            ['message' => __('auth.too_many_requests', ['seconds' => app(LocalDigits::class)->convert((string) ($exception->getHeaders()['Retry-After'] ?? 60))])],
            $exception->getStatusCode(),
            $exception->getHeaders(),
        ));
    })->create();
