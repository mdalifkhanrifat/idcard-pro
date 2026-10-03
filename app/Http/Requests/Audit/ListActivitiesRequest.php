<?php

namespace App\Http\Requests\Audit;

use App\Data\Audit\ActivityFilter;
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
            'filter.event' => ['nullable', 'in:created,updated,deleted'],
            'filter.causer' => ['nullable', 'string', 'size:26'],
            'filter.date_from' => ['nullable', 'date_format:Y-m-d'],
            'filter.date_to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function toFilter(): ActivityFilter
    {
        return ActivityFilter::fromArray($this->validated());
    }
}
