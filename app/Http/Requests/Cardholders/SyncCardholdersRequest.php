<?php

namespace App\Http\Requests\Cardholders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A batch of people from an HR system. Only the shape is checked here: each record is checked on
 * its own by the cardholder rules (CardholderApiSyncService), so one bad record fails alone.
 */
class SyncCardholdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cardholders.sync');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'records' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.config('cardholder_sync.max_records')],
            'records.*' => ['required', 'array'],
            'dry_run' => ['sometimes', 'boolean'],
            'create_missing_lookups' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return (array) __('cardholders.sync.attributes');
    }
}
