<?php

namespace App\Http\Requests\CustomFields;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Key, entity and type can only be set when the field is created.
 */
class SaveCustomFieldRequest extends FormRequest
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
        /** @var CustomField|null $field */
        $field = $this->route('custom_field');
        $type = $field?->type->value ?? $this->input('type');

        return [
            'entity' => $field ? ['prohibited'] : ['required', Rule::in(CustomField::ENTITIES)],
            'key' => $field ? ['prohibited'] : [
                'required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('custom_fields')->where('tenant_id', app(TenantContext::class)->id())->where('entity', $this->input('entity')),
            ],
            'type' => $field ? ['prohibited'] : ['required', new Enum(CustomFieldType::class)],
            'label' => ['required', 'string', 'max:255'],
            'label_local' => ['nullable', 'string', 'max:255'],
            'group' => ['nullable', 'string', 'max:100'],
            'options' => $type === CustomFieldType::Select->value ? ['required', 'array', 'min:1', 'max:200'] : ['prohibited'],
            'options.*' => ['string', 'max:100', 'distinct'],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
