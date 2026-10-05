<?php

namespace App\Http\Requests\Fonts;

use App\Models\TenantFont;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The file type is recognized from its contents by FontInspector, not from the name or MIME type.
 */
class UploadTenantFontRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('fonts.create');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:15360'],
            // Empty: the family name stored in the font itself
            'family' => ['nullable', 'string', 'max:60', 'regex:/^[\pL\pN][\pL\pN \-_]*$/u'],
            'weight' => ['nullable', 'integer', Rule::in(TenantFont::WEIGHTS)],
            'style' => ['nullable', Rule::in(TenantFont::STYLES)],
            // Fonts are licensed software: the organization confirms it may use this one
            'licence_confirmed' => ['accepted'],
        ];
    }
}
