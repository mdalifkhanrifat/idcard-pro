<?php

namespace App\Http\Requests\Appointments;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving an appointment (5.4d): a place, a day, a time on the organization's clock ("HH:MM") and a note — only
 * what is sent. Its own answer is taken back as it is: objects (`location`, `cardholder`, `family_member` as
 * {id, …}, `service` as {key, …}) are read by their id or key, read-only fields are left out. Whether it can
 * move is the booking service's to say.
 */
class MoveAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('appointments.book');
    }

    protected function prepareForValidation(): void
    {
        $ids = [];
        foreach (['location' => 'id', 'cardholder' => 'id', 'family_member' => 'id', 'service' => 'key'] as $field => $key) {
            $value = $this->input($field);
            if (is_array($value) && array_key_exists($key, $value)) {
                $ids[$field] = $value[$key];
            }
        }
        $this->merge($ids);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'location' => ['sometimes', 'required', 'string', 'size:26', Rule::exists('appointment_locations', 'public_id')->where('tenant_id', $tenantId)],
            'date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Taken back from its own answer; refused when different (AppointmentBookingService::move())
            'service' => ['sometimes', 'string', 'max:40'],
            'cardholder' => ['sometimes', 'string', 'max:26'],
            'family_member' => ['sometimes', 'nullable', 'string', 'max:26'],
        ];
    }
}
