<?php

namespace App\Http\Requests\MasterData;

use App\Models\MasterDataItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates creating or updating an item of the master data type given in the route.
 */
class SaveMasterDataItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('item') ? 'master_data.update' : 'master_data.create');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $type = (string) $this->route('type');
        $definition = config("master_data.types.{$type}");
        $tenantId = app(TenantContext::class)->id();
        /** @var MasterDataItem|null $item */
        $item = $this->route('item');

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('master_data_items')
                    ->where('tenant_id', $tenantId)
                    ->where('type', $type)
                    ->where(fn ($q) => $this->input('parent_id')
                        ? $q->whereIn('parent_id', MasterDataItem::query()->where('public_id', $this->input('parent_id'))->select('id'))
                        : $q->whereNull('parent_id'))
                    ->ignore($item?->id),
            ],
            'name_local' => ['nullable', 'string', 'max:255'],
            'code' => $definition['has_code']
                ? ['nullable', 'string', 'max:32', Rule::unique('master_data_items')->where('tenant_id', $tenantId)->where('type', $type)->ignore($item?->id)]
                : ['prohibited'],
            'parent_id' => $definition['parent']
                ? ['required', 'string', Rule::exists('master_data_items', 'public_id')->where('tenant_id', $tenantId)->where('type', $definition['parent'])]
                : ['prohibited'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
