<?php

namespace App\Http\Requests\Webhooks;

use App\Data\Webhooks\WebhookDeliveryFilter;
use App\Enums\WebhookDeliveryStatus;
use App\Support\ListPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListWebhookDeliveriesRequest extends FormRequest
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
        return [
            'filter' => ['sometimes', 'array:status,event'],
            'filter.status' => ['nullable', Rule::enum(WebhookDeliveryStatus::class)],
            'filter.event' => ['nullable', 'string', 'max:60'],
            'per_page' => ListPage::rule(),
        ];
    }

    public function toFilter(): WebhookDeliveryFilter
    {
        return WebhookDeliveryFilter::fromArray($this->validated());
    }
}
