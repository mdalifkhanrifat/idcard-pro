<?php

namespace App\Http\Requests\Signatories;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSignatoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('signatory') ? 'signatories.update' : 'signatories.create');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_local' => ['nullable', 'string', 'max:255'],
            'designation' => ['required', 'string', 'max:255'],
            'designation_local' => ['nullable', 'string', 'max:255'],
            'authority_type_id' => [
                'nullable', 'string', 'size:26',
                Rule::exists('master_data_items', 'public_id')->where('tenant_id', app(TenantContext::class)->id())->where('type', 'authority_type'),
            ],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
