<?php

namespace App\Http\Requests\CardTemplates;

use App\Enums\TemplateAudience;
use App\Enums\TemplateOrientation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class SaveCardTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('template') ? 'templates.update' : 'templates.create');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $lookup = fn (string $type) => ['nullable', 'string', 'size:26', Rule::exists('master_data_items', 'public_id')->where('tenant_id', $tenantId)->where('type', $type)];
        $required = $this->route('template') ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'code' => [$required, 'string', 'max:20', 'regex:/^[A-Za-z0-9\-_]+$/'],
            'card_type_id' => $lookup('card_type'),
            'audience' => ['sometimes', new Enum(TemplateAudience::class)],
            'orientation' => ['sometimes', new Enum(TemplateOrientation::class)],
            'size_unit' => ['sometimes', Rule::in(['mm', 'px'])],
            'width' => ['sometimes', 'numeric', 'gt:0', $this->input('size_unit') === 'px' ? 'max:5000' : 'max:500'],
            'height' => ['sometimes', 'numeric', 'gt:0', $this->input('size_unit') === 'px' ? 'max:5000' : 'max:500'],
            'authority_type_id' => $lookup('authority_type'),
            'validity_months' => ['nullable', 'integer', 'between:1,240'],
            'is_double_sided' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
