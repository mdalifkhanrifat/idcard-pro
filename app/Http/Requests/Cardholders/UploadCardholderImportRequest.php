<?php

namespace App\Http\Requests\Cardholders;

use Illuminate\Foundation\Http\FormRequest;

class UploadCardholderImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cardholders.import');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // CSV is often reported as text/plain, so the extension is checked as well as the content.
            'file' => ['required', 'file', 'max:'.config('cardholder_import.max_kb'), 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx'],
            'create_missing_lookups' => ['sometimes', 'boolean'],
            'update_existing' => ['sometimes', 'boolean'],
        ];
    }
}
