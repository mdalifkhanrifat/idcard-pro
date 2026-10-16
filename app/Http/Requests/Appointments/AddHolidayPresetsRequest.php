<?php

namespace App\Http\Requests\Appointments;

use App\Services\Appointments\HolidayPresets;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The organization's country's holidays chosen to add at once (keys of config appointments.holiday_presets).
 */
class AddHolidayPresetsRequest extends FormRequest
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
        return [
            'keys' => ['required', 'array', 'min:1'],
            'keys.*' => ['string', Rule::in(app(HolidayPresets::class)->keys(app(TenantContext::class)->get()))],
        ];
    }
}
