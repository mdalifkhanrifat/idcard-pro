<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Appointments\AppointmentLoad;
use App\Services\Appointments\BookedAppointmentLoad;
use App\Services\Audit\AuditChangeDescriber;
use App\Services\Cards\CardRequirements;
use App\Services\Integrations\IntegrationSettings;
use App\Support\Pdf\DisabledPdfRenderer;
use App\Support\Pdf\GotenbergPdfRenderer;
use App\Support\Pdf\PdfRenderer;
use App\Support\Tenancy\TenantContext;
use App\Support\Validation\LocalizedValidator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
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
        // One per request or job: the audit's field names are looked up once per language
        $this->app->scoped(AuditChangeDescriber::class);
        // What is already booked at a place (bookings come with 5.4)
        $this->app->bind(AppointmentLoad::class, BookedAppointmentLoad::class);

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

        // Numbers in validation messages in the reader's digits
        Validator::resolver(fn ($translator, array $data, array $rules, array $messages, array $attributes) => new LocalizedValidator($translator, $data, $rules, $messages, $attributes));

        // Platform administrators may do anything inside the tenant they are acting on.
        Gate::before(fn (User $user) => $user->is_platform_admin ? true : null);

        // Users and integration accounts share a table but are managed apart: the users API can never reach an
        // account (its role, password or status), nor the other way round
        Route::bind('user', fn (string $id) => User::query()->people()->where('public_id', $id)->firstOrFail());
        Route::bind('integration', fn (string $id) => User::query()->integrations()->where('public_id', $id)->firstOrFail());

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
        // The public booking link (5.6): no sign-in, so per address
        RateLimiter::for('public-booking', fn (Request $request) => Limit::perMinute((int) config('appointments.public.per_minute'))->by('ip:'.$request->ip()));

        RateLimiter::for('signed-files', fn (Request $request) => Limit::perMinute((int) config('cards.print.files_per_minute'))
            ->by($request->user() !== null ? 'user:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip()));

        // Systems calling the API: per account, so a second token adds nothing, as many as its organization chose.
        // Runs before the organization is resolved, so it asks the account. People in a browser carry no token.
        RateLimiter::for('api-tokens', function (Request $request): Limit {
            $user = $request->user();
            if ($request->bearerToken() === null || ! $user instanceof User) {
                return Limit::none();
            }

            return Limit::perMinute($user->tenant?->integrationSettings()['requests_per_minute'] ?? IntegrationSettings::defaultRequestsPerMinute())->by('token-user:'.$user->id);
        });
    }
}
