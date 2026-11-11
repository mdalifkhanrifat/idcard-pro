<?php

namespace App\Http\Requests\Cardholders;

use App\Services\Integrations\SyncSettingsRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The HR sync's own settings on their own (the page's HR sync tab): the HR field names and deleting old runs.
 * They are the organization's settings, so changing them takes settings.update; what is left out stays.
 */
class SaveSyncSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.update');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(SyncSettingsRules $rules): array
    {
        return $rules->rules();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $names = (array) __('validation.attributes');

        return array_filter([
            'sync_prune_log' => $names['integrations.sync_prune_log'] ?? null,
            'sync_log_days' => $names['integrations.sync_log_days'] ?? null,
            'sync_fields' => $names['integrations.sync_fields'] ?? null,
        ], is_string(...));
    }
}
