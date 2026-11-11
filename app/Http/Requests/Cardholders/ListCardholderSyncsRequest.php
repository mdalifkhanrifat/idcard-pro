<?php

namespace App\Http\Requests\Cardholders;

use App\Enums\CardholderSyncKind;
use App\Support\ListPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The HR sync's run log: whoever manages integrations sees every run, an HR system's account its own.
 */
class ListCardholderSyncsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('integrations.manage') || $this->user()->can('cardholders.sync');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:kind'],
            'filter.kind' => ['nullable', Rule::enum(CardholderSyncKind::class)],
            'per_page' => ListPage::rule(),
        ];
    }
}
