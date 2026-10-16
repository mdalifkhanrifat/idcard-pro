<?php

namespace App\Http\Requests\Appointments;

use App\Models\AppointmentClosure;
use App\Services\Appointments\ClosureDates;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Days appointments are not taken, new or changed (a change sends only what changes): the place (a public id;
 * null: every place), the first and last day (the organization's, both whole), whether it comes every year, and
 * why, in two languages.
 */
class SaveAppointmentClosureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.update');
    }

    /**
     * The place as the API answers it ({id, name, name_local}) is taken back by its id, so a client may send
     * back what it read.
     */
    protected function prepareForValidation(): void
    {
        $location = $this->input('location');
        if (is_array($location) && array_key_exists('id', $location)) {
            $this->merge(['location' => $location['id']]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = $this->closure() === null ? 'required' : 'sometimes';

        return [
            'location' => ['sometimes', 'nullable', 'string', 'size:26',
                Rule::exists('appointment_locations', 'public_id')->where('tenant_id', app(TenantContext::class)->id())],
            'date_from' => [$required, 'date_format:Y-m-d'],
            'date_to' => [$required, 'date_format:Y-m-d'],
            'yearly' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
            'reason_local' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    /**
     * The days as they will be saved (a change on the closure's own): in order, not too long.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['date_from', 'date_to', 'yearly'])) {
                return;
            }
            $closure = $this->closure();
            $from = (string) $this->input('date_from', $closure?->date_from->toDateString());
            $to = (string) $this->input('date_to', $closure?->date_to->toDateString());
            $yearly = $this->has('yearly') ? $this->boolean('yearly') : (bool) $closure?->yearly;
            foreach (ClosureDates::mistakes($from, $to, $yearly) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        }];
    }

    private function closure(): ?AppointmentClosure
    {
        $closure = $this->route('appointment_closure');

        return $closure instanceof AppointmentClosure ? $closure : null;
    }
}
