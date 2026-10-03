<?php

namespace App\Services\Auth;

use App\Enums\LoginEventType;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Repositories\Contracts\PasswordResetTokenRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Audit\AuditService;
use App\Services\Tenants\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tenant-aware password reset. The same e-mail may exist in several organizations,
 * so each token belongs to exactly one user row and is stored only as a hash.
 */
class PasswordResetService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private PasswordResetTokenRepositoryInterface $tokens,
        private TenantService $tenants,
        private AuditService $audit,
    ) {}

    /**
     * Send a reset link when the details match an active account. Never reveals whether they did.
     */
    public function sendResetLink(?string $organization, string $email): void
    {
        $hasOrganization = $organization !== null && trim($organization) !== '';
        $tenant = $hasOrganization ? $this->tenants->findBySlug($organization) : null;

        if ($hasOrganization && $tenant === null) {
            return;
        }

        $user = $this->users->findForLogin($tenant?->id, strtolower($email));

        if ($user === null || ! $user->isActive()) {
            return;
        }

        $token = Str::random(64);
        $this->tokens->replace($user->id, hash('sha256', $token), now()->addMinutes(config('security.password.reset_token_minutes')));

        $user->notify(new ResetPasswordNotification($token));
    }

    /**
     * Set a new password with a valid token. The token is single-use; all API tokens are revoked.
     */
    public function reset(string $token, string $password, Request $request): void
    {
        $userId = $this->tokens->findUserIdByValidHash(hash('sha256', $token));
        $user = $userId ? $this->users->findAnyTenant($userId) : null;

        if ($user === null) {
            throw ValidationException::withMessages(['token' => __('auth.reset_token_invalid')]);
        }

        DB::transaction(function () use ($user, $password): void {
            $user->forceFill([
                'password' => $password,
                'password_changed_at' => now(),
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'remember_token' => Str::random(60),
            ])->save();
            $user->tokens()->delete();
            $this->tokens->deleteForUser($user->id);
        });

        $this->audit->recordLogin(LoginEventType::PasswordReset, $request, $user);
    }
}
