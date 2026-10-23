<?php

namespace App\Http\Requests\Audit;

use App\Data\Audit\LoginEventFilter;
use App\Enums\LoginChannel;
use App\Enums\LoginEventType;
use App\Support\ListPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ListLoginEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('audit.view');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:event,channel,identifier,ip_address,date_from,date_to'],
            'filter.event' => ['nullable', new Enum(LoginEventType::class)],
            'filter.channel' => ['nullable', new Enum(LoginChannel::class)],
            'filter.identifier' => ['nullable', 'string', 'max:255'],
            'filter.ip_address' => ['nullable', 'ip'],
            'filter.date_from' => ['nullable', 'date_format:Y-m-d'],
            'filter.date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filter.date_from'],
            'per_page' => ListPage::rule(),
        ];
    }

    public function toFilter(): LoginEventFilter
    {
        return LoginEventFilter::fromArray($this->validated());
    }
}
