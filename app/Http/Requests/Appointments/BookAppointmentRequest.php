<?php

namespace App\Http\Requests\Appointments;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A booking (5.4): a place, a kind of visit, a cardholder (or a family member of theirs), a day of the
 * organization and — when times are booked — a time on its clock ("HH:MM"), and a note. Whether it can be
 * booked is the booking service's to say (the same rules as the free times).
 */
class BookAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('appointments.book');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $inTenant = fn (string $table) => Rule::exists($table, 'public_id')->where('tenant_id', $tenantId);

        return [
            'location' => ['required', 'string', 'size:26', $inTenant('appointment_locations')],
            'service' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
            'cardholder' => ['required', 'string', 'size:26', $inTenant('cardholders')],
            'family_member' => ['sometimes', 'nullable', 'string', 'size:26', $inTenant('family_members')],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
