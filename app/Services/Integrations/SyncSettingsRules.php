<?php

namespace App\Services\Integrations;

use App\Services\Cardholders\CardholderSyncMapper;
use Closure;

/**
 * The rules for the HR sync's settings (settings.integrations), wherever they are saved: with the organization
 * settings, or on their own (PUT /sync/cardholders/settings).
 */
class SyncSettingsRules
{
    public function __construct(private CardholderSyncMapper $mapper) {}

    /**
     * @param  string  $prefix  where the settings sit in the request ("integrations." with the organization settings)
     * @return array<string, array<int, mixed>>
     */
    public function rules(string $prefix = ''): array
    {
        return [
            "{$prefix}sync_prune_log" => ['sometimes', 'boolean'],
            "{$prefix}sync_log_days" => ['sometimes', 'integer', 'between:'.(int) config('cardholder_sync.log_days.min').','.(int) config('cardholder_sync.log_days.max')],
            // The HR system's own field names (6.3f): theirs => ours; the whole map is sent, an empty one clears it
            "{$prefix}sync_fields" => ['sometimes', 'array', 'max:'.(int) config('cardholder_sync.max_field_names'), function (string $attribute, mixed $value, Closure $fail): void {
                $problem = $this->fieldNamesProblem((array) $value);
                if ($problem !== null) {
                    $fail($problem);
                }
            }],
        ];
    }

    /**
     * What is wrong with a map of the HR system's field names, if anything: each name is a name (not a path), each
     * stands for a field the sync takes, and no two for the same.
     *
     * @param  array<array-key, mixed>  $names
     */
    public function fieldNamesProblem(array $names): ?string
    {
        if ($names !== [] && array_is_list($names)) {
            return __('integrations.sync_fields.not_a_map');
        }
        $targets = $this->mapper->targets();
        $seen = [];
        foreach ($names as $theirs => $ours) {
            if (! IntegrationSettings::isFieldName((string) $theirs)) {
                return __('integrations.sync_fields.bad_name', ['name' => $theirs]);
            }
            if (! is_string($ours) || ! in_array($ours, $targets, true)) {
                return __('integrations.sync_fields.unknown_field', ['name' => $theirs, 'field' => is_scalar($ours) ? (string) $ours : '']);
            }
            if (isset($seen[$ours])) {
                return __('integrations.sync_fields.twice', ['field' => $ours, 'names' => $seen[$ours].', '.$theirs]);
            }
            $seen[$ours] = (string) $theirs;
        }

        return null;
    }
}
