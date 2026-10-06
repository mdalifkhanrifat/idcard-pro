<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Pdf\DisabledPdfRenderer;
use App\Support\Pdf\GotenbergPdfRenderer;
use App\Support\Pdf\PdfRenderer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);

        $this->app->bind(PdfRenderer::class, fn () => match (config('pdf.driver')) {
            'gotenberg' => new GotenbergPdfRenderer((string) config('pdf.gotenberg.url'), (int) config('pdf.gotenberg.timeout')),
            default => new DisabledPdfRenderer,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Platform administrators may do anything inside the tenant they are acting on.
        Gate::before(fn (User $user) => $user->is_platform_admin ? true : null);

        // Password complexity. `uncompromised()` is intentionally not used: it sends
        // password hash prefixes to an external service (haveibeenpwned.com).
        Password::defaults(fn () => Password::min(config('security.password.min_length'))
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols());

        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('login:'.strtolower((string) $request->input('login', $request->input('email'))).'|'.$request->ip()),
        ]);
    }
}
