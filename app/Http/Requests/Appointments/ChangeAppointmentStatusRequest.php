<?php

namespace App\Http\Requests\Appointments;

use App\Enums\AppointmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Taking an appointment a step (5.4d), by those who manage appointments: the status it becomes, and a reason
 * when it is turned down or cancelled. Which steps are allowed is AppointmentStatus::next()'s to say.
 */
class ChangeAppointmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('appointments.manage');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(AppointmentStatus::class)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function status(): AppointmentStatus
    {
        return AppointmentStatus::from((string) $this->validated('status'));
    }
}
