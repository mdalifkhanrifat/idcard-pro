<?php

namespace App\Http\Requests\Tenants;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_platform_admin;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'alpha_dash', 'max:63', 'unique:tenants,slug'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'timezone'],
            'locale' => ['nullable', 'in:en,bn'],
            'owner.name' => ['required', 'string', 'max:120'],
            'owner.email' => ['required', 'email', 'max:255'],
            'owner.password' => ['required', Password::defaults()],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slug' => strtolower((string) $this->input('slug'))]);
    }
}
