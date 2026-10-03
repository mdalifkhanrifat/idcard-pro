<?php

namespace App\Http\Requests\Cardholders;

use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class SaveFamilyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cardholders.update');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'relation' => ['required', new Enum(FamilyRelation::class)],
            'name' => ['required', 'string', 'max:255'],
            'name_local' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', new Enum(Gender::class)],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:30'],
            'blood_group_id' => ['nullable', 'string', 'size:26', Rule::exists('master_data_items', 'public_id')->where('tenant_id', $tenantId)->where('type', 'blood_group')],
            'national_id' => ['nullable', 'string', 'max:40'],
            'passport_no' => ['nullable', 'string', 'max:40'],
            'identification_mark' => ['nullable', 'string', 'max:255'],
            'card_no' => ['nullable', 'string', 'max:40'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
