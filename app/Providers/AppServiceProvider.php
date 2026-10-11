<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Cards\CardRequirements;
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
        // One per request or job: requirements read the organization's settings once
        $this->app->scoped(CardRequirements::class);

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

        // Files behind signed links (photos, signatures, biometric images, fonts, design images): a print batch
        // loads several per card, so the limit follows the batch size; per user, else per address (PDF engine)
        RateLimiter::for('signed-files', fn (Request $request) => Limit::perMinute((int) config('cards.print.files_per_minute'))
            ->by($request->user() !== null ? 'user:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip()));
    }
}
