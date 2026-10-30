<?php

namespace App\Http\Requests\Integrations;

use App\Enums\UserKind;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Unique;

/**
 * A new account needs its name and role; a change may send only what changes, and takes back the account's own
 * answer. The Owner role is never an account's: it can do what no outside system should.
 */
class SaveIntegrationRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->id();
        $need = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$need, 'required', 'string', 'max:120', $this->uniqueName($tenantId)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'role' => [$need, 'required', 'string', Rule::exists('roles', 'name')->where('tenant_id', $tenantId), Rule::notIn([PermissionRegistry::OWNER_ROLE])],
            'status' => ['sometimes', 'required', new Enum(UserStatus::class)],
        ];
    }

    /**
     * Two accounts of one name could not be told apart in the audit log.
     */
    private function uniqueName(?int $tenantId): Unique
    {
        /** @var User|null $account */
        $account = $this->route('integration');

        return Rule::unique('users', 'name')
            ->where('tenant_id', $tenantId)
            ->where('kind', UserKind::Integration->value)
            ->whereNull('deleted_at')
            ->ignore($account?->id);
    }
}
