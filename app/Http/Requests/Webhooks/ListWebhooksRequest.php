<?php

namespace App\Http\Requests\Webhooks;

use App\Data\Webhooks\WebhookFilter;
use App\Support\ListPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListWebhooksRequest extends FormRequest
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
        $sorts = collect(WebhookFilter::SORTS)->flatMap(fn (string $s) => [$s, "-{$s}"])->all();

        return [
            'filter' => ['sometimes', 'array:search,enabled'],
            'filter.search' => ['nullable', 'string', 'max:100'],
            'filter.enabled' => ['nullable', 'in:0,1,true,false'],
            'sort' => ['sometimes', Rule::in($sorts)],
            'per_page' => ListPage::rule(),
        ];
    }

    public function toFilter(): WebhookFilter
    {
        return WebhookFilter::fromArray($this->validated());
    }
}
