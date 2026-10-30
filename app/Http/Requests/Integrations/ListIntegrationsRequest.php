<?php

namespace App\Http\Requests\Integrations;

use App\Data\Integrations\IntegrationFilter;
use App\Enums\UserStatus;
use App\Support\ListPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ListIntegrationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('integrations.manage');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $sorts = collect(IntegrationFilter::SORTS)->flatMap(fn (string $s) => [$s, "-{$s}"])->all();

        return [
            'filter' => ['sometimes', 'array:search,status,role'],
            'filter.search' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', new Enum(UserStatus::class)],
            'filter.role' => ['nullable', 'string', 'max:64'],
            'sort' => ['sometimes', Rule::in($sorts)],
            'per_page' => ListPage::rule(),
        ];
    }

    public function toFilter(): IntegrationFilter
    {
        return IntegrationFilter::fromArray($this->validated());
    }
}
