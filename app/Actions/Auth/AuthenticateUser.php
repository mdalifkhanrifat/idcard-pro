<?php

namespace App\Actions\Auth;

use App\Enums\LoginEventType;
use App\Models\LoginEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Verifies login credentials for a tenant user or a platform administrator.
 *
 * The lockout counter lives on the user row so it survives new sessions,
 * browsers and devices. Error messages never reveal whether an account exists.
 */
class AuthenticateUser
{
    /**
     * @param  array{organization?: string|null, login: string, password: string}  $credentials
     */
    public function handle(array $credentials, Request $request): User
    {
        $identifier = trim($credentials['login']);
        $tenant = $this->resolveTenant($credentials['organization'] ?? null, $identifier, $request);
        $user = $this->findUser($tenant, $identifier);

        if ($user === null) {
            Hash::check($credentials['password'], '$2y$12$'.str_repeat('a', 53));
            LoginEvent::record(LoginEventType::Failed, $request, tenant: $tenant, identifier: $identifier);

            throw $this->invalidCredentials();
        }

        if ($user->isLocked()) {
            LoginEvent::record(LoginEventType::Blocked, $request, $user, $tenant, $identifier);

            throw ValidationException::withMessages([
                'login' => __('auth.locked', ['minutes' => (int) ceil(now()->diffInMinutes($user->locked_until))]),
            ]);
        }

        if (! Hash::check($credentials['password'], $user->password)) {
            $this->registerFailure($user, $request, $tenant, $identifier);

            throw $this->invalidCredentials();
        }

        if (! $user->isActive() || ($tenant !== null && ! $tenant->isActive())) {
            LoginEvent::record(LoginEventType::Blocked, $request, $user, $tenant, $identifier);

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
        if ($organization === null || $organization === '') {
            return null;
        }

        $tenant = Tenant::query()->where('slug', strtolower(trim($organization)))->first();

        if ($tenant === null) {
            LoginEvent::record(LoginEventType::Failed, $request, identifier: $identifier);

            throw $this->invalidCredentials();
        }

        return $tenant;
    }

    private function findUser(?Tenant $tenant, string $identifier): ?User
    {
        $column = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return User::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where($column, $column === 'email' ? strtolower($identifier) : $identifier)
            ->when(
                $tenant,
                fn ($query) => $query->where('tenant_id', $tenant->id),
                fn ($query) => $query->whereNull('tenant_id')->where('is_platform_admin', true),
            )
            ->first();
    }

    private function registerFailure(User $user, Request $request, ?Tenant $tenant, string $identifier): void
    {
        $attempts = $user->failed_login_attempts + 1;
        $maxAttempts = config('security.lockout.max_attempts');

        if ($attempts >= $maxAttempts) {
            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => now()->addMinutes(config('security.lockout.lockout_minutes')),
            ])->save();

            LoginEvent::record(LoginEventType::Locked, $request, $user, $tenant, $identifier);

            return;
        }

        $user->forceFill(['failed_login_attempts' => $attempts])->save();
        LoginEvent::record(LoginEventType::Failed, $request, $user, $tenant, $identifier);
    }

    private function invalidCredentials(): ValidationException
    {
        return ValidationException::withMessages(['login' => __('auth.failed')]);
    }
}
