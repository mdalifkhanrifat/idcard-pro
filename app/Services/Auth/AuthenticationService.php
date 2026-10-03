<?php

namespace App\Services\Auth;

use App\Enums\LoginEventType;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Audit\AuditService;
use App\Services\Tenants\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in flow: credential check, persistent lockout, two-factor challenge and session start/end.
 *
 * Error messages never reveal whether an organization or account exists.
 */
class AuthenticationService
{
    public const TWO_FACTOR_SESSION_KEY = 'auth.two_factor';

    private const TWO_FACTOR_TTL_MINUTES = 5;

    /** Valid bcrypt hash used to keep timing equal when the user does not exist. */
    private const DUMMY_HASH = '$2y$12$aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function __construct(
        private UserRepositoryInterface $users,
        private TenantService $tenants,
        private TwoFactorAuthenticator $twoFactor,
        private AuditService $audit,
    ) {}

    /**
     * Check credentials and either start the session or open a two-factor challenge.
     *
     * @param  array{organization?: string|null, login: string, password: string, remember?: bool}  $credentials
     * @return bool whether a two-factor code is still required
     */
    public function attempt(array $credentials, Request $request): bool
    {
        $user = $this->verifyCredentials($credentials, $request);
        $remember = (bool) ($credentials['remember'] ?? false);

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put(self::TWO_FACTOR_SESSION_KEY, [
                'user_id' => $user->id,
                'remember' => $remember,
                'expires_at' => now()->addMinutes(self::TWO_FACTOR_TTL_MINUTES)->timestamp,
            ]);
            $this->audit->recordLogin(LoginEventType::TwoFactorRequired, $request, $user);

            return true;
        }

        $this->startSession($request, $user, $remember);

        return false;
    }

    /**
     * Finish a pending two-factor challenge with an authenticator or recovery code.
     */
    public function completeTwoFactor(Request $request, ?string $code, ?string $recoveryCode): void
    {
        $pending = $request->session()->get(self::TWO_FACTOR_SESSION_KEY);

        if (! is_array($pending) || $pending['expires_at'] < now()->timestamp) {
            $request->session()->forget(self::TWO_FACTOR_SESSION_KEY);

            throw ValidationException::withMessages(['code' => __('auth.two_factor_expired')]);
        }

        $user = $this->users->findAnyTenant((int) $pending['user_id']);

        if ($user === null || ! $this->twoFactor->verifyForUser($user, $code, $recoveryCode)) {
            $this->audit->recordLogin(LoginEventType::TwoFactorFailed, $request, $user);

            throw ValidationException::withMessages(['code' => __('auth.two_factor_invalid')]);
        }

        $this->startSession($request, $user, (bool) $pending['remember']);
    }

    public function logout(Request $request): void
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->audit->recordLogin(LoginEventType::LoggedOut, $request, $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function startSession(Request $request, User $user, bool $remember): void
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->forget(self::TWO_FACTOR_SESSION_KEY);

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $this->audit->recordLogin(LoginEventType::Succeeded, $request, $user);
    }

    /**
     * @param  array{organization?: string|null, login: string, password: string}  $credentials
     */
    private function verifyCredentials(array $credentials, Request $request): User
    {
        $identifier = trim($credentials['login']);
        $tenant = $this->resolveTenant($credentials['organization'] ?? null, $identifier, $request);
        $user = $this->users->findForLogin($tenant?->id, $identifier);

        if ($user === null) {
            Hash::check($credentials['password'], self::DUMMY_HASH);
            $this->audit->recordLogin(LoginEventType::Failed, $request, tenant: $tenant, identifier: $identifier);

            throw $this->invalidCredentials();
        }

        if ($user->isLocked()) {
            $this->audit->recordLogin(LoginEventType::Blocked, $request, $user, $tenant, $identifier);

            throw ValidationException::withMessages([
                'login' => __('auth.locked', ['minutes' => (int) ceil(now()->diffInMinutes($user->locked_until))]),
            ]);
        }

        if (! Hash::check($credentials['password'], $user->password)) {
            $this->registerFailure($user, $request, $tenant, $identifier);

            throw $this->invalidCredentials();
        }

        if (! $user->isActive() || ($tenant !== null && ! $tenant->isActive())) {
            $this->audit->recordLogin(LoginEventType::Blocked, $request, $user, $tenant, $identifier);

            throw ValidationException::withMessages(['login' => __('auth.inactive')]);
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $credentials['password']]);
        }
        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->save();

        return $user;
    }

    private function resolveTenant(?string $organization, string $identifier, Request $request): ?Tenant
    {
        if ($organization === null || trim($organization) === '') {
            return null;
        }

        $tenant = $this->tenants->findBySlug($organization);

        if ($tenant === null) {
            $this->audit->recordLogin(LoginEventType::Failed, $request, identifier: $identifier);

            throw $this->invalidCredentials();
        }

        return $tenant;
    }

    /**
     * The failure counter lives on the user row so it survives new sessions, browsers and devices.
     */
    private function registerFailure(User $user, Request $request, ?Tenant $tenant, string $identifier): void
    {
        $attempts = $user->failed_login_attempts + 1;

        if ($attempts >= config('security.lockout.max_attempts')) {
            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => now()->addMinutes(config('security.lockout.lockout_minutes')),
            ])->save();
            $this->audit->recordLogin(LoginEventType::Locked, $request, $user, $tenant, $identifier);

            return;
        }

        $user->forceFill(['failed_login_attempts' => $attempts])->save();
        $this->audit->recordLogin(LoginEventType::Failed, $request, $user, $tenant, $identifier);
    }

    private function invalidCredentials(): ValidationException
    {
        return ValidationException::withMessages(['login' => __('auth.failed')]);
    }
}
