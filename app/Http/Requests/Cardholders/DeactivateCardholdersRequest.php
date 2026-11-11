<?php

namespace App\Http\Requests\Cardholders;

use App\Enums\CardholderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * People who left, by employee number, from an HR system. Only the shape is checked here: each number
 * gets its own result (CardholderApiSyncService::deactivate), so one unknown number fails alone.
 */
class DeactivateCardholdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cardholders.sync');
    }

    /**
     * A number sent as a JSON number is the same employee number as text, as in the records of a sync.
     */
    protected function prepareForValidation(): void
    {
        $numbers = $this->input('employee_nos');
        if (is_array($numbers)) {
            $this->merge(['employee_nos' => array_map(fn (mixed $number): mixed => is_int($number) ? (string) $number : $number, $numbers)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_nos' => ['required', 'array', 'list', 'min:1', 'max:'.config('cardholder_sync.max_records')],
            'employee_nos.*' => ['required', 'string', 'max:40'],
            // Active is not a way out: a person comes back through POST /sync/cardholders with "status": "active"
            'status' => ['sometimes', Rule::enum(CardholderStatus::class)->except([CardholderStatus::Active])],
            'dry_run' => ['sometimes', 'boolean'],
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
