<?php

namespace App\Http\Requests\Tenants;

use App\Services\Biometrics\BiometricSettings;
use App\Services\Cardholders\CardholderSyncMapper;
use App\Services\Cards\CardNumberFormat;
use App\Services\Integrations\IntegrationSettings;
use App\Services\Notifications\NotificationSettingsRules;
use App\Services\Webhooks\WebhookSettings;
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
        ], ...$this->biometricRules(), ...$this->notificationRules(), ...$this->integrationRules()];
    }

    /**
     * How long integration tokens last (6.1): both whole days, the usual length within the most.
     *
     * @return array<string, array<int, mixed>>
     */
    private function integrationRules(): array
    {
        $limit = 'between:1,'.(int) config('integrations.tokens.limit');

        return [
            'integrations' => ['sometimes', 'array:token_days,max_token_days,requests_per_minute,sync_fields'],
            'integrations.token_days' => ['sometimes', 'integer', $limit, function (string $attribute, mixed $value, \Closure $fail): void {
                $most = $this->input('integrations.max_token_days', $this->tenantContext()->get()->integrationSettings()['max_token_days']);
                if ((int) $value > (int) $most) {
                    $fail(__('integrations.days_beyond_most'));
                }
            }],
            'integrations.max_token_days' => ['sometimes', 'integer', $limit],
            'integrations.requests_per_minute' => ['sometimes', 'integer', 'between:'.(int) config('integrations.requests.min').','.(int) config('integrations.requests.max')],
            // The HR system's own field names (6.3f): theirs => ours; the whole map is sent, an empty one clears it
            'integrations.sync_fields' => ['sometimes', 'array', 'max:'.(int) config('cardholder_sync.max_field_names'), function (string $attribute, mixed $value, \Closure $fail): void {
                $problem = $this->syncFieldsProblem((array) $value);
                if ($problem !== null) {
                    $fail($problem);
                }
            }],
            'webhooks' => ['sometimes', 'array:prune_log,log_days,attempts,disable_after'],
            'webhooks.prune_log' => ['sometimes', 'boolean'],
            'webhooks.log_days' => ['sometimes', 'integer', 'between:'.(int) config('webhooks.log_days.min').','.(int) config('webhooks.log_days.max')],
            'webhooks.attempts' => ['sometimes', 'integer', 'between:1,'.WebhookSettings::mostAttempts()],
            'webhooks.disable_after' => ['sometimes', 'integer', 'between:'.(int) config('webhooks.disable_limits.min').','.(int) config('webhooks.disable_limits.max')],
        ];
    }

    /**
     * What is wrong with a map of the HR system's field names, if anything: each name is a name (not a path), each
     * stands for a field the sync takes, and no two for the same.
     *
     * @param  array<array-key, mixed>  $names
     */
    private function syncFieldsProblem(array $names): ?string
    {
        if ($names !== [] && array_is_list($names)) {
            return __('integrations.sync_fields.not_a_map');
        }
        $targets = app(CardholderSyncMapper::class)->targets();
        $seen = [];
        foreach ($names as $theirs => $ours) {
            if (! IntegrationSettings::isFieldName((string) $theirs)) {
                return __('integrations.sync_fields.bad_name', ['name' => $theirs]);
            }
            if (! is_string($ours) || ! in_array($ours, $targets, true)) {
                return __('integrations.sync_fields.unknown_field', ['name' => $theirs, 'field' => is_scalar($ours) ? (string) $ours : '']);
            }
            if (isset($seen[$ours])) {
                return __('integrations.sync_fields.twice', ['field' => $ours, 'names' => $seen[$ours].', '.$theirs]);
            }
            $seen[$ours] = (string) $theirs;
        }

        return null;
    }

    /**
     * Notifications (5.2), the same as PUT /notifications/settings.
     *
     * @return array<string, array<int, mixed>>
     */
    private function notificationRules(): array
    {
        return NotificationSettingsRules::rules();
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
            'biometrics' => ['sometimes', 'array:enabled,modalities,family_members,required_before,print_on_card,print_source'],
            'biometrics.enabled' => ['required_with:biometrics', 'boolean'],
            'biometrics.modalities' => ['sometimes', 'array:'.implode(',', array_keys($modalities))],
            'biometrics.family_members' => ['sometimes', 'boolean'],
            'biometrics.required_before' => ['sometimes', 'array'],
            'biometrics.required_before.*' => ['distinct', Rule::in((array) config('biometrics.required_before'))],
            'biometrics.print_on_card' => ['sometimes', 'boolean'],
            'biometrics.print_source' => ['sometimes', Rule::in((array) config('biometrics.print_sources'))],
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
     * Checks across fields, only while biometrics are on (turning them on checks everything): a modality
     * in use needs positions and a driver, and cannot ask for more positions than it may capture; at
     * least one modality must be in use. Settings kept while biometrics are off never block a save.
     *
     * @return array<int, callable(ValidationValidator): void>
     */
    public function after(): array
    {
        return [$this->checkNotifications(...), function (ValidationValidator $validator): void {
            $change = $this->input('biometrics');
            if (! is_array($change) || $validator->errors()->has('biometrics*')) {
                return;
            }
            // Checked as it will be saved: the change applied to the current settings
            $raw = BiometricSettings::merge((array) ($this->tenantContext()->get()->settings['biometrics'] ?? []), $change);
            $settings = BiometricSettings::normalize($raw);
            if (! $settings['enabled']) {
                return;
            }
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
            if ($inUse === 0) {
                $validator->errors()->add('biometrics.modalities', __('biometrics.settings.nothing_on'));
            }
        }];
    }

    private function checkNotifications(ValidationValidator $validator): void
    {
        NotificationSettingsRules::check($validator, $this->input('notifications'), (array) ($this->tenantContext()->get()->settings['notifications'] ?? []));
    }

    private function tenantContext(): TenantContext
    {
        return app(TenantContext::class);
    }
}
