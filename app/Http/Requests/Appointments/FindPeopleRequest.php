<?php

namespace App\Http\Requests\Appointments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Finding a person to book for (5.5b): a name, local name or employee number, two characters at least.
 */
class FindPeopleRequest extends FormRequest
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
        return [
            'search' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }
}
