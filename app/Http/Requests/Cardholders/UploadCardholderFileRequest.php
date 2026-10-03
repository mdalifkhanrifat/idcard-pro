<?php

namespace App\Http\Requests\Cardholders;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a file upload against its collection in config/cardholder_files.php.
 */
class UploadCardholderFileRequest extends FormRequest
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
        $collections = config('cardholder_files.collections');
        $definition = $collections[(string) $this->input('collection')] ?? null;

        return [
            'collection' => ['required', 'string', Rule::in(array_keys($collections))],
            'file' => $definition
                ? ['required', 'file', 'mimes:'.implode(',', $definition['mimes']), 'max:'.$definition['max_kb']]
                : ['required', 'file'],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
