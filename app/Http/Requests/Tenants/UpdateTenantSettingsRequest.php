<?php

namespace App\Http\Requests\Tenants;

use App\Services\Biometrics\BiometricSettings;
use App\Services\Cards\CardNumberFormat;
use App\Support\Tenancy\TenantContext;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidationValidator;

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
        return [...[
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
            'verification' => ['sometimes', 'array:enabled,fields,show_revoke_reason,link,note,log_checks'],
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
            'verification.log_checks' => ['sometimes', 'boolean'],
            'card_number_format' => ['sometimes', 'string', 'max:60', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && ($problem = CardNumberFormat::problem($value)) !== null) {
                    $fail($problem);
                }
            }],
        ], ...$this->biometricRules()];
    }

    /**
     * Biometrics (Phase 4): what each modality captures, how many, the quality accepted and the drivers
     * stations may use; lists that are missing mean none (a form cannot send an empty list).
     *
     * @return array<string, array<int, mixed>>
     */
    private function biometricRules(): array
    {
        $modalities = (array) config('biometrics.modalities');
        $rules = [
            'biometrics' => ['sometimes', 'array:enabled,modalities,family_members,required_before,print_on_card'],
            'biometrics.enabled' => ['required_with:biometrics', 'boolean'],
            'biometrics.modalities' => ['sometimes', 'array:'.implode(',', array_keys($modalities))],
            'biometrics.family_members' => ['sometimes', 'boolean'],
            'biometrics.required_before' => ['sometimes', 'array'],
            'biometrics.required_before.*' => ['distinct', Rule::in((array) config('biometrics.required_before'))],
            'biometrics.print_on_card' => ['sometimes', 'boolean'],
        ];
        foreach ($modalities as $name => $modality) {
            $key = "biometrics.modalities.{$name}";
            $rules += [
                $key => ['sometimes', 'array:mode,count,positions,substitutes,min_quality,max_attempts,drivers'],
                "{$key}.mode" => ["required_with:{$key}", Rule::in((array) config('biometrics.modes'))],
                "{$key}.count" => ['sometimes', 'integer', 'between:1,'.count($modality['positions'])],
                "{$key}.positions" => ['sometimes', 'array'],
                "{$key}.positions.*" => ['distinct', Rule::in(array_keys($modality['positions']))],
                "{$key}.substitutes" => ['sometimes', 'boolean'],
                "{$key}.min_quality" => ['sometimes', 'integer', 'between:0,100'],
                "{$key}.max_attempts" => ['sometimes', 'integer', 'between:1,10'],
                "{$key}.drivers" => ['sometimes', 'array'],
                "{$key}.drivers.*" => ['distinct', Rule::in(BiometricSettings::driversFor($name))],
            ];
        }

        return $rules;
    }

    /**
     * Checks across fields: a modality in use needs positions and a driver, and cannot ask for more
     * positions than it may capture; biometrics turned on needs at least one modality in use.
     *
     * @return array<int, callable(ValidationValidator): void>
     */
    public function after(): array
    {
        return [function (ValidationValidator $validator): void {
            $change = $this->input('biometrics');
            if (! is_array($change) || $validator->errors()->has('biometrics*')) {
                return;
            }
            // Checked as it will be saved: the change applied to the current settings
            $raw = BiometricSettings::merge((array) ($this->tenantContext()->get()->settings['biometrics'] ?? []), $change);
            $settings = BiometricSettings::normalize($raw);
            $inUse = 0;
            foreach ($settings['modalities'] as $name => $modality) {
                if ($modality['mode'] === 'off') {
                    continue;
                }
                $inUse++;
                $key = "biometrics.modalities.{$name}";
                if ($modality['positions'] === []) {
                    $validator->errors()->add("{$key}.positions", __('biometrics.settings.positions_needed'));
                }
                if ($modality['drivers'] === []) {
                    $validator->errors()->add("{$key}.drivers", __('biometrics.settings.drivers_needed'));
                }
                // Normalizing caps the count, so the asked-for count is checked before it
                if (! $modality['substitutes'] && (int) ($raw['modalities'][$name]['count'] ?? $modality['count']) > max(1, count($modality['positions']))) {
                    $validator->errors()->add("{$key}.count", __('biometrics.settings.count_too_high'));
                }
            }
            if ($settings['enabled'] && $inUse === 0) {
                $validator->errors()->add('biometrics.modalities', __('biometrics.settings.nothing_on'));
            }
        }];
    }

    private function tenantContext(): TenantContext
    {
        return app(TenantContext::class);
    }
}
