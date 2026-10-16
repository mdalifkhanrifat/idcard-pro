<?php

namespace App\Http\Requests\Appointments;

use App\Services\Appointments\AppointmentSettingsRules;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The organization's appointment settings ({appointments: {...}}) as a partial change: what is sent changes, the
 * rest stays (e.g. only days_ahead).
 */
class UpdateAppointmentSettingsRequest extends FormRequest
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
        return AppointmentSettingsRules::rules();
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => AppointmentSettingsRules::check(
            $validator,
            $this->input('appointments'),
            (array) (app(TenantContext::class)->get()->settings['appointments'] ?? []),
        )];
    }
}
