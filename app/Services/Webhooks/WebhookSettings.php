<?php

namespace App\Services\Webhooks;

/**
 * An organization's rules for its webhooks, on top of config/webhooks.php.
 */
class WebhookSettings
{
    /**
     * @param  array<string, mixed>  $saved
     * @return array{prune_log: bool, log_days: int, attempts: int, disable_after: int}
     */
    public static function normalize(array $saved): array
    {
        $defaults = (array) config('webhooks.defaults');
        $disable = (array) config('webhooks.disable_limits');

        return [
            'prune_log' => (bool) ($saved['prune_log'] ?? $defaults['prune_log']),
            'log_days' => (int) ($saved['log_days'] ?? $defaults['log_days']),
            // Within what this installation allows now, whatever was saved under an older schedule or limit
            'attempts' => max(1, min((int) ($saved['attempts'] ?? self::mostAttempts()), self::mostAttempts())),
            'disable_after' => max((int) $disable['min'], min((int) ($saved['disable_after'] ?? config('webhooks.disable_after')), (int) $disable['max'])),
        ];
    }

    /**
     * Once at first, then once after each wait of the installation's schedule.
     */
    public static function mostAttempts(): int
    {
        return count(self::schedule()) + 1;
    }

    /**
     * The seconds to wait before each try after the first, for this many tries in all: the first of the
     * installation's waits, never a schedule of the organization's own.
     *
     * @return array<int, int>
     */
    public static function backoff(int $attempts): array
    {
        return array_slice(self::schedule(), 0, max(0, $attempts - 1));
    }

    /**
     * What the settings may be set to, for a page to offer (and say when each try comes).
     *
     * @return array{attempts: array{min: int, max: int, backoff: array<int, int>}, disable_after: array{min: int, max: int}}
     */
    public static function options(): array
    {
        $disable = (array) config('webhooks.disable_limits');

        return [
            'attempts' => ['min' => 1, 'max' => self::mostAttempts(), 'backoff' => self::schedule()],
            'disable_after' => ['min' => (int) $disable['min'], 'max' => (int) $disable['max']],
        ];
    }

    /**
     * @return array<int, int>
     */
    private static function schedule(): array
    {
        return array_values(array_map(intval(...), (array) config('webhooks.retry.backoff')));
    }
}
