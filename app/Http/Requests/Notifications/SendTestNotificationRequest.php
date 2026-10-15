<?php

namespace App\Http\Requests\Notifications;

use App\Data\Notifications\NotificationRecipient;
use App\Services\Notifications\NotificationSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A test message: any channel of the registry (not only those the organization has on — it checks one before
 * turning it on), to the address typed, or else the sender's own for that channel; the address must look like
 * the channel's kind (config('notifications.addresses')).
 */
class SendTestNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('notifications.send');
    }

    protected function prepareForValidation(): void
    {
        $channel = $this->input('channel');
        if (is_string($channel) && blank($this->input('address')) && in_array($channel, NotificationSettings::channels(), true)) {
            $this->merge(['address' => NotificationRecipient::forUser($this->user())->addressFor($channel)]);
        }
        if (is_string($this->input('address'))) {
            $this->merge(['address' => trim($this->input('address'))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $kind = config('notifications.channels.'.$this->string('channel').'.address');

        return [
            'channel' => ['required', 'string', Rule::in(NotificationSettings::channels())],
            'address' => ['required', 'string', ...(array) config("notifications.addresses.{$kind}", [])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $kind = config('notifications.channels.'.$this->string('channel').'.address');

        return [
            'address.required' => __('notifications.test.address_needed'),
            'address.regex' => __("notifications.addresses.{$kind}"),
        ];
    }
}
