<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238) two-factor authentication compatible with Google Authenticator, Microsoft Authenticator, Authy, etc.
 */
class TwoFactorAuthenticator
{
    public function __construct(private Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function qrCodeSvg(User $user, string $secret): string
    {
        $label = $user->tenant?->name ? "{$user->tenant->name}:{$user->email}" : $user->email;
        $url = $this->engine->getQRCodeUrl(config('security.two_factor.issuer'), $label, $secret);

        return (new Writer(new ImageRenderer(new RendererStyle(192, 0), new SvgImageBackEnd)))->writeString($url);
    }

    public function verify(string $secret, string $code): bool
    {
        return (bool) $this->engine->verifyKey($secret, preg_replace('/\s+/', '', $code), 1);
    }

    /**
     * @return array<int, string>
     */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, config('security.two_factor.recovery_codes')))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();
    }

    /**
     * Verify a TOTP code or consume a one-time recovery code.
     */
    public function verifyForUser(User $user, ?string $code, ?string $recoveryCode): bool
    {
        if ($code !== null && $code !== '') {
            return $this->verify($user->two_factor_secret, $code);
        }

        $codes = $user->two_factor_recovery_codes ?? [];
        $index = array_search(Str::lower(trim((string) $recoveryCode)), $codes, true);

        if ($recoveryCode === null || $index === false) {
            return false;
        }

        unset($codes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

        return true;
    }
}
