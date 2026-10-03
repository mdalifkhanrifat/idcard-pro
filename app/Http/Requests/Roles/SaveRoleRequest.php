<?php

namespace App\Http\Requests\Roles;

use App\Support\Permissions\PermissionRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('role') ? 'roles.update' : 'roles.create');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:64',
                Rule::unique('roles', 'name')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($this->route('role') ? (int) $this->route('role') : null),
            ],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(app(PermissionRegistry::class)->all()->all())],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return $this->validated('permissions', []);
    }
}
