<?php

namespace App\Http\Requests\Tenants;

use App\Services\Cards\CardNumberFormat;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
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
            'card_number_on_reissue' => ['sometimes', Rule::in((array) config('cards.number_on_reissue'))],
            // Card verification (3.7): public page on or off, what it shows, own link address and a note
            'verification' => ['sometimes', 'array:enabled,fields,show_revoke_reason,link,note'],
            'verification.enabled' => ['required_with:verification', 'boolean'],
            // Missing = none (a form cannot send an empty list)
            'verification.fields' => ['sometimes', 'array'],
            'verification.fields.*' => ['distinct', Rule::in((array) config('cards.verification.fields'))],
            'verification.show_revoke_reason' => ['sometimes', 'boolean'],
            'verification.link' => ['nullable', 'string', 'max:200', function (string $attribute, mixed $value, \Closure $fail): void {
                // {code} is not valid in a URL itself, so the address is checked with a sample code in its place
                $sample = str_replace('{code}', 'abc123', (string) $value);
                if (! str_contains((string) $value, '{code}') || Validator::make(['link' => $sample], ['link' => 'url:http,https'])->fails()) {
                    $fail(__('cards.verification.link_invalid'));
                }
            }],
            'verification.note' => ['nullable', 'string', 'max:500'],
            'card_number_format' => ['sometimes', 'string', 'max:60', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && ($problem = CardNumberFormat::problem($value)) !== null) {
                    $fail($problem);
                }
            }],
        ];
    }
}
