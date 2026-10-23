<?php

namespace App\Http\Requests\Appointments\PublicBooking;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving one's own appointment on the public link (5.6): to another place, day or time, by the public's rules.
 */
class PublicMoveRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'location' => ['sometimes', 'string', 'size:26', Rule::exists('appointment_locations', 'public_id')->where('tenant_id', app(TenantContext::class)->id())],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }
}
