<?php

namespace App\Http\Requests\Appointments\PublicBooking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Starting to prove who one is on the public booking link (5.6): the employee number.
 */
class IdentifyRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['employee_no' => ['required', 'string', 'max:50']];
    }
}
