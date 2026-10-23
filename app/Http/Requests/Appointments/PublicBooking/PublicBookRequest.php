<?php

namespace App\Http\Requests\Appointments\PublicBooking;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Booking on the public link (5.6), for oneself or a family member: the place, the kind of visit, the day and time
 * and a note. The person is the session's; the rest is checked by the booking's own rules (the public's).
 */
class PublicBookRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $inTenant = fn (string $table) => Rule::exists($table, 'public_id')->where('tenant_id', app(TenantContext::class)->id());

        return [
            'location' => ['required', 'string', 'size:26', $inTenant('appointment_locations')],
            'service' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
            'family_member' => ['sometimes', 'nullable', 'string', 'size:26', $inTenant('family_members')],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
