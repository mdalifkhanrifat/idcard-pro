<?php

namespace App\Services\Integrations;

/**
 * An organization's rules for integration tokens, on top of config/integrations.php, and the HR system's own
 * field names for the sync (sync_fields: theirs => ours, e.g. "emp_id" => "employee_no").
 */
class IntegrationSettings
{
    /**
     * @param  array<string, mixed>  $saved
     * @return array{token_days: int, max_token_days: int, requests_per_minute: int, sync_fields: array<string, string>, sync_prune_log: bool, sync_log_days: int}
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
            'sync_fields' => self::syncFields($saved['sync_fields'] ?? []),
            // The HR sync's run log: kept, unless the organization chose to delete runs older than its days
            'sync_prune_log' => (bool) ($saved['sync_prune_log'] ?? config('cardholder_sync.defaults.prune_log')),
            'sync_log_days' => max((int) config('cardholder_sync.log_days.min'), min((int) ($saved['sync_log_days'] ?? config('cardholder_sync.defaults.log_days')), (int) config('cardholder_sync.log_days.max'))),
        ];
    }

    /**
     * Whether an HR system's field name can be kept: a name, never a path (the audit log and the request rules read
     * dots as nesting).
     */
    public static function isFieldName(mixed $name): bool
    {
        return is_string($name) && $name === trim($name) && $name !== '' && mb_strlen($name) <= 100 && ! str_contains($name, '.');
    }

    /**
     * @return array<string, string>
     */
    private static function syncFields(mixed $saved): array
    {
        $names = [];
        foreach (is_array($saved) ? $saved : [] as $theirs => $ours) {
            if (self::isFieldName($theirs) && is_string($ours) && $ours !== '') {
                $names[(string) $theirs] = $ours;
            }
        }
        ksort($names);

        return $names;
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
