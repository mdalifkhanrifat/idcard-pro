<?php

namespace App\Services\Webhooks;

/**
 * What a name points at right now, on this server.
 */
class WebhookHostResolver
{
    /**
     * @return array<int, string> every address of the name; none when it has none
     */
    public function addresses(string $host): array
    {
        $ipv4 = gethostbynamel($host) ?: [];
        $ipv6 = @dns_get_record($host, DNS_AAAA) ?: [];

        return array_values(array_unique([...$ipv4, ...array_column($ipv6, 'ipv6')]));
    }
}
