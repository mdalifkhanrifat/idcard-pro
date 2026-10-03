<?php

namespace App\Http\Requests\Cardholders;

use App\Enums\QualificationKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SaveQualificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cardholders.update');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', new Enum(QualificationKind::class)],
            'title' => ['required', 'string', 'max:255'],
            'institution' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'between:1900,'.(now()->year + 1)],
            'subject' => ['nullable', 'string', 'max:255'],
            'degree' => ['nullable', 'string', 'max:255'],
            'result' => ['nullable', 'string', 'max:40'],
            'certificate_no' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
