<?php

namespace App\Http\Requests\Tenants;

use App\Services\Cards\CardNumberFormat;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.update');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'locale' => ['required', Rule::in(['en', 'bn'])],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            // Landscape default for new templates (legacy Template Setup)
            'default_card_size' => ['sometimes', 'array:width,height'],
            'default_card_size.width' => ['required_with:default_card_size', 'numeric', 'between:10,500', 'gte:default_card_size.height'],
            'default_card_size.height' => ['required_with:default_card_size', 'numeric', 'between:10,500'],
            'card_number_format' => ['sometimes', 'string', 'max:60', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && ($problem = CardNumberFormat::problem($value)) !== null) {
                    $fail($problem);
                }
            }],
        ];
    }
}
