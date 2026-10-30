<?php

namespace App\Services\Integrations;

/**
 * An organization's rules for integration tokens, on top of config/integrations.php.
 */
class IntegrationSettings
{
    /**
     * @param  array<string, mixed>  $saved
     * @return array{token_days: int, max_token_days: int}
     */
    public static function normalize(array $saved): array
    {
        $most = (int) ($saved['max_token_days'] ?? config('integrations.tokens.max_days'));

        return [
            // Lowering the most a token may last brings the usual length down with it
            'token_days' => min((int) ($saved['token_days'] ?? config('integrations.tokens.days')), $most),
            'max_token_days' => $most,
        ];
    }
}
