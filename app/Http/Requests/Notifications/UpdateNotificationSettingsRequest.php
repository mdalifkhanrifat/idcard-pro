<?php

namespace App\Http\Requests\Notifications;

use App\Services\Notifications\NotificationSettingsRules;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The organization's notification settings alone ({notifications: {...}}), checked by the same rules as in the
 * organization's settings, as a partial change: what is sent changes, the rest stays (e.g. only log_days).
 */
class UpdateNotificationSettingsRequest extends FormRequest
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
        $rules = NotificationSettingsRules::rules(partial: true);
        $rules['notifications'] = ['required', ...array_diff($rules['notifications'], ['sometimes'])];

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => NotificationSettingsRules::check(
            $validator,
            $this->input('notifications'),
            (array) (app(TenantContext::class)->get()->settings['notifications'] ?? []),
            partial: true,
        )];
    }
}
