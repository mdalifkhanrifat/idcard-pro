<?php

namespace App\Services\Webhooks;

/**
 * Whether an address may be told anything. A webhook makes this server call out, so an address must not lead back
 * inside: to itself, its database, or the network around it (server-side request forgery).
 *
 * This reads the address as written and asks no one, so saving one sends nothing anywhere. What a name resolves to
 * can change later, so each delivery checks that for itself.
 */
class WebhookAddress
{
    /** Names that only ever mean something inside. */
    private const INNER_NAMES = ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback'];

    private const INNER_ENDINGS = ['.localhost', '.local', '.internal', '.intranet', '.lan', '.home', '.corp'];

    /**
     * What is wrong with the address, in the reader's language; null when it may be used.
     */
    public static function problem(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($parts === false || $host === '' || ! in_array($scheme, ['https', 'http'], true)) {
            return (string) __('webhooks.address.invalid');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return (string) __('webhooks.address.credentials');
        }
        if ((bool) config('webhooks.allow_private')) {
            return null;
        }
        if ($scheme !== 'https') {
            return (string) __('webhooks.address.https');
        }

        return self::isInner($host) ? (string) __('webhooks.address.inner') : null;
    }

    private static function isInner(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ! self::isPublic($host);
        }

        // No dot, or a last part with no letter: not a public name ("intranet", "2130706433", "0x7f.1")
        $last = (string) strrchr('.'.$host, '.');
        if (! str_contains($host, '.') || ! preg_match('/^\.[a-z][a-z0-9-]*[a-z0-9]$/', $last) || str_starts_with($last, '.0x')) {
            return true;
        }

        return in_array($host, self::INNER_NAMES, true) || array_filter(self::INNER_ENDINGS, fn (string $ending) => str_ends_with($host, $ending)) !== [];
    }

    /**
     * Also what a resolved address must be at delivery.
     */
    public static function isPublic(string $ip): bool
    {
        // An IPv4 address written inside IPv6 is that IPv4 address
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ip = substr($ip, 7);
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
