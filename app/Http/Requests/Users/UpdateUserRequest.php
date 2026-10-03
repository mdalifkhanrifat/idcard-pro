<?php

namespace App\Http\Requests\Users;

use App\Enums\UserStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users.update');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $userId = $this->route('user')->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->where('tenant_id', $tenantId)->ignore($userId)],
            'username' => ['nullable', 'string', 'alpha_dash', 'max:64', Rule::unique('users')->where('tenant_id', $tenantId)->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', Rule::in(['en', 'bn'])],
            'status' => ['required', new Enum(UserStatus::class)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'unlock' => ['sometimes', 'boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('tenant_id', $tenantId)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower((string) $this->input('email'))]);
    }
}
