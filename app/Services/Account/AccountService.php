<?php

namespace App\Services\Account;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Self-service account settings for the signed-in user.
 */
class AccountService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private TwoFactorAuthenticator $twoFactor,
    ) {}

    /**
     * @param  array{name: string, phone?: string|null, locale?: string|null}  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        return $this->users->update($user, $data);
    }

    /**
     * Change the password and sign out every other session of the user.
     */
    public function changePassword(User $user, string $password): void
    {
        $user->forceFill(['password' => $password, 'password_changed_at' => now()])->save();

        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        if ($guard->user() !== null) {
            $guard->logoutOtherDevices($password);
        }
    }

    /**
     * Start two-factor enrolment.
     *
     * @return array{secret: string, qr_svg: string}
     */
    public function startTwoFactor(User $user): array
    {
        $secret = $this->twoFactor->generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();

        return ['secret' => $secret, 'qr_svg' => $this->twoFactor->qrCodeSvg($user, $secret)];
    }

    /**
     * Confirm enrolment with a code from the authenticator app.
     *
     * @return array<int, string> one-time recovery codes
     */
    public function confirmTwoFactor(User $user, string $code): array
    {
        if ($user->two_factor_secret === null || ! $this->twoFactor->verify($user->two_factor_secret, $code)) {
            throw ValidationException::withMessages(['code' => __('auth.two_factor_invalid')]);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    public function disableTwoFactor(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    public function tokens(User $user): Collection
    {
        return $user->tokens()->latest()->get();
    }

    public function createToken(User $user, string $name, ?int $expiresInDays): NewAccessToken
    {
        return $user->createToken($name, ['*'], $expiresInDays ? now()->addDays($expiresInDays) : null);
    }

    /**
     * Revoke one of the user's tokens. Idempotent: unknown ids are ignored.
     */
    public function revokeToken(User $user, int $tokenId): void
    {
        $user->tokens()->whereKey($tokenId)->delete();
    }
}
