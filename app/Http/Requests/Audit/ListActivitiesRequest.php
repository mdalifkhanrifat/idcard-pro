<?php

namespace App\Http\Requests\Audit;

use App\Data\Audit\ActivityFilter;
use App\Support\ListPage;
use Illuminate\Foundation\Http\FormRequest;

class ListActivitiesRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:log_name,event,causer,date_from,date_to'],
            'filter.log_name' => ['nullable', 'string', 'max:50'],
            // Any event name (meta.events lists those that occurred, e.g. requirements_overridden)
            'filter.event' => ['nullable', 'string', 'max:50', 'regex:/^[a-z_]+$/'],
            'filter.causer' => ['nullable', 'string', 'size:26'],
            'filter.date_from' => ['nullable', 'date_format:Y-m-d'],
            'filter.date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filter.date_from'],
            'per_page' => ListPage::rule(),
        ];
    }

    public function toFilter(): ActivityFilter
    {
        return ActivityFilter::fromArray($this->validated());
    }
}
