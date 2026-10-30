<?php

namespace App\Http\Requests\Integrations;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

class StoreIntegrationTokenRequest extends FormRequest
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
        $most = app(TenantContext::class)->get()->integrationSettings()['max_token_days'];

        return [
            'name' => ['required', 'string', 'max:100'],
            'expires_in_days' => ['sometimes', 'required', 'integer', 'between:1,'.$most],
        ];
    }
}
