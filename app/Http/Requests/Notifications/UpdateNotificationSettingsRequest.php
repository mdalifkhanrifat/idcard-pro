<?php

namespace App\Http\Requests\Notifications;

use App\Services\Notifications\NotificationSettingsRules;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The organization's notification settings alone ({notifications: {...}}), checked exactly as in the organization's
 * settings: what is sent changes, the rest stays.
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
        $rules = NotificationSettingsRules::rules();
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
        )];
    }
}
