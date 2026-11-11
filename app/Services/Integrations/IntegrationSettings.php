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
     * @return array{token_days: int, max_token_days: int, requests_per_minute: int, sync_fields: array<string, string>}
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
