<?php

namespace App\Services\Webhooks;

use Symfony\Component\HttpFoundation\IpUtils;

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

    /** Not reachable on the public internet either, though IpUtils::PRIVATE_SUBNETS leaves them out. */
    private const ALSO_INNER = ['192.0.0.0/24', '192.88.99.0/24', '224.0.0.0/4', '100::/64', 'fec0::/10', 'ff00::/8'];

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
        $onlyHosts = (array) config('webhooks.only_hosts');
        if ($onlyHosts !== [] && ! in_array($host, $onlyHosts, true)) {
            return (string) __('webhooks.address.only_hosts');
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
     * Also what a resolved address must be at delivery. An IPv4 address carried inside IPv6 (mapped, 6to4, NAT64,
     * Teredo) is never public here, whatever it carries.
     */
    public static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false && ! IpUtils::isPrivateIp($ip) && ! IpUtils::checkIp($ip, self::ALSO_INNER);
    }
}
