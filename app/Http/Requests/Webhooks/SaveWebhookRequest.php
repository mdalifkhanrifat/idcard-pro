<?php

namespace App\Http\Requests\Webhooks;

use App\Services\Webhooks\WebhookAddress;
use App\Services\Webhooks\WebhookEvents;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new webhook needs its name, address and events; a change may send only what changes, and takes back the webhook's
 * own answer.
 */
class SaveWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('integrations.manage');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $need = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$need, 'required', 'string', 'max:120'],
            'url' => [$need, 'required', 'string', 'max:2000', function (string $attribute, mixed $value, Closure $fail): void {
                $problem = WebhookAddress::problem((string) $value);
                if ($problem !== null) {
                    $fail($problem);
                }
            }],
            'events' => [$need, 'required', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'distinct', Rule::in(WebhookEvents::keys())],
            'enabled' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
