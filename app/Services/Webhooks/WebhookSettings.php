<?php

namespace App\Services\Webhooks;

/**
 * An organization's rules for its webhooks, on top of config/webhooks.php.
 */
class WebhookSettings
{
    /**
     * @param  array<string, mixed>  $saved
     * @return array{prune_log: bool, log_days: int}
     */
    public static function normalize(array $saved): array
    {
        $defaults = (array) config('webhooks.defaults');

        return [
            'prune_log' => (bool) ($saved['prune_log'] ?? $defaults['prune_log']),
            'log_days' => (int) ($saved['log_days'] ?? $defaults['log_days']),
        ];
    }
}
