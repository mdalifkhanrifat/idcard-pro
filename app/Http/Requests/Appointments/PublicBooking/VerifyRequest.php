<?php

namespace App\Http\Requests\Appointments\PublicBooking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The answer to a public booking challenge (5.6): the code, the date of birth or the national ID number — the one
 * the organization's method asks for.
 */
class VerifyRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'challenge' => ['required', 'string', 'size:40'],
            'code' => ['nullable', 'string', 'max:12'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'national_id' => ['nullable', 'string', 'max:30'],
        ];
    }
}
