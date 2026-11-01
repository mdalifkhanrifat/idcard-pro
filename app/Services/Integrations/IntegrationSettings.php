<?php

namespace App\Services\Integrations;

/**
 * An organization's rules for integration tokens, on top of config/integrations.php.
 */
class IntegrationSettings
{
    /**
     * @param  array<string, mixed>  $saved
     * @return array{token_days: int, max_token_days: int, requests_per_minute: int}
     */
    public static function normalize(array $saved): array
    {
        $most = (int) ($saved['max_token_days'] ?? config('integrations.tokens.max_days'));

        return [
            // Lowering the most a token may last brings the usual length down with it
            'token_days' => min((int) ($saved['token_days'] ?? config('integrations.tokens.days')), $most),
            'max_token_days' => $most,
            // Within what this installation allows now, whatever was saved under an older limit
            'requests_per_minute' => self::requestsWithinLimits((int) ($saved['requests_per_minute'] ?? config('integrations.requests.per_minute'))),
        ];
    }

    /**
     * The API calls a minute for someone who has no organization (the platform's own people): the installation's.
     */
    public static function defaultRequestsPerMinute(): int
    {
        return self::requestsWithinLimits((int) config('integrations.requests.per_minute'));
    }

    private static function requestsWithinLimits(int $perMinute): int
    {
        return max((int) config('integrations.requests.min'), min($perMinute, (int) config('integrations.requests.max')));
    }
}
