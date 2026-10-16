<?php

namespace App\Http\Requests\Appointments;

use App\Models\AppointmentLocation;
use App\Services\Appointments\AppointmentSettings;
use App\Services\Appointments\WeeklyHours;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A place people come to for an appointment, new or changed (a change sends only what changes): names, address,
 * how many are served at once and per day, the kinds of visit it takes (null: every kind) and its weekly hours.
 */
class SaveAppointmentLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.update');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $location = $this->route('appointment_location');
        $creating = ! $location instanceof AppointmentLocation;
        $between = function (string $limit): string {
            [$min, $max] = AppointmentSettings::limit($limit);

            return "between:{$min},{$max}";
        };
        $tenant = app(TenantContext::class)->get();

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120',
                Rule::unique('appointment_locations', 'name')->where('tenant_id', $tenant->id)->ignore($creating ? null : $location->id)],
            'name_local' => ['sometimes', 'nullable', 'string', 'max:120'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'slot_capacity' => ['sometimes', 'integer', $between('slot_capacity')],
            'day_capacity' => ['sometimes', 'integer', $between('day_capacity')],
            // null: every kind of visit (those the organization adds later too)
            'services' => ['sometimes', 'nullable', 'array', 'min:1'],
            'services.*' => ['string', 'distinct', Rule::in(array_keys($tenant->appointmentSettings()['services']))],
            'hours' => ['sometimes', 'array:'.implode(',', WeeklyHours::weekdays())],
            'hours.*' => ['array', 'max:'.(int) config('appointments.locations.periods_per_day')],
            'hours.*.*' => ['array', 'size:2'],
            'hours.*.*.*' => ['string', 'regex:'.WeeklyHours::TIME],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,9999'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('hours*') || ! is_array($this->input('hours'))) {
                return;
            }
            foreach (WeeklyHours::mistakes((array) $this->input('hours')) as $field => $message) {
                $validator->errors()->add($field, __($message));
            }
        }];
    }
}
