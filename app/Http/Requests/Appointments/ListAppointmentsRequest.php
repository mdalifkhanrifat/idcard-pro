<?php

namespace App\Http\Requests\Appointments;

use App\Data\Appointments\AppointmentFilter;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Support\ListPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * The appointments list (5.4c). filter[status] takes one status, several separated by commas, or a list.
 */
class ListAppointmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('appointments.view');
    }

    protected function prepareForValidation(): void
    {
        $status = $this->input('filter.status');
        if (is_string($status)) {
            $this->merge(['filter' => [...(array) $this->input('filter'), 'status' => array_values(array_filter(array_map('trim', explode(',', $status)), fn (string $s) => $s !== ''))]]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $sorts = collect(AppointmentFilter::SORTS)->flatMap(fn (string $s) => [$s, "-{$s}"])->all();

        return [
            'filter' => ['sometimes', 'array:search,from,to,past,location,status,service,source,cardholder,family_member'],
            'filter.search' => ['nullable', 'string', 'max:100'],
            'filter.from' => ['nullable', 'date_format:Y-m-d'],
            'filter.to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filter.from'],
            'filter.past' => ['nullable', 'boolean'],
            // Public ids of another organization simply match nothing
            'filter.location' => ['nullable', 'string', 'size:26'],
            'filter.status' => ['nullable', 'array'],
            'filter.status.*' => ['string', new Enum(AppointmentStatus::class)],
            // Any key: meta lists the settings' kinds and those of bookings since removed from them
            'filter.service' => ['nullable', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
            'filter.source' => ['nullable', new Enum(AppointmentSource::class)],
            'filter.cardholder' => ['nullable', 'string', 'size:26'],
            'filter.family_member' => ['nullable', 'string', 'size:26'],
            'sort' => ['sometimes', Rule::in($sorts)],
            'per_page' => ListPage::rule(),
        ];
    }

    public function toFilter(): AppointmentFilter
    {
        return AppointmentFilter::fromArray($this->validated());
    }
}
