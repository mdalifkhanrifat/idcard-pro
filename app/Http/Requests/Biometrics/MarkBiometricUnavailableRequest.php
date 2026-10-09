<?php

namespace App\Http\Requests\Biometrics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A position that cannot be captured (e.g. a missing finger) and why.
 */
class MarkBiometricUnavailableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('biometrics.enroll');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'family_member' => ['sometimes', 'nullable', 'string', 'max:26'],
            'modality' => ['required', 'string', Rule::in(array_keys((array) config('biometrics.modalities')))],
            'position' => ['required', 'string', 'max:40'],
            'reason' => ['required', 'string', Rule::in((array) config('biometrics.unavailable_reasons'))],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
