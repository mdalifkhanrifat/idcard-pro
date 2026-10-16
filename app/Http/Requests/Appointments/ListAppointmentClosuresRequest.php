<?php

namespace App\Http\Requests\Appointments;

use App\Data\Appointments\AppointmentClosureFilter;
use App\Support\ListPage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ListAppointmentClosuresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::any(['appointments.view', 'settings.update']);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:location,from,to,past'],
            'filter.location' => ['nullable', 'string', 'size:26',
                Rule::exists('appointment_locations', 'public_id')->where('tenant_id', app(TenantContext::class)->id())],
            'filter.from' => ['nullable', 'date_format:Y-m-d'],
            'filter.to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filter.from'],
            'filter.past' => ['nullable', 'boolean'],
            'per_page' => ListPage::rule(),
        ];
    }

    public function toFilter(): AppointmentClosureFilter
    {
        return AppointmentClosureFilter::fromArray($this->validated());
    }
}
