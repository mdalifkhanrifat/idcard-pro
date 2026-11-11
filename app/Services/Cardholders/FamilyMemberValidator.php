<?php

namespace App\Services\Cardholders;

use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation rules for family member input, shared by the form (SaveFamilyMemberRequest) and the HR sync
 * (FamilySyncService). The blood group is a public id.
 */
class FamilyMemberValidator
{
    /**
     * @param  Cardholder|null  $cardholder  whose member it is; null for a person not saved yet (a dry run)
     * @param  FamilyMember|null  $member  the member being updated (ignored by unique rules)
     * @return array<string, array<int, mixed>>
     */
    public function rules(?Cardholder $cardholder = null, ?FamilyMember $member = null): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            // The HR system's own id for the member: one per member of the same cardholder
            'external_id' => ['nullable', 'string', 'max:100', Rule::unique('family_members', 'external_id')->where('cardholder_id', $cardholder->id ?? 0)->ignore($member?->id)],
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

    /**
     * Field names in the reader's language, as reports name a member's fields.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $names = [];
        foreach ((array) __('reports.columns') as $key => $name) {
            if (is_string($key) && str_starts_with($key, 'family.') && is_string($name)) {
                $names[substr($key, 7)] = $name;
            }
        }

        return [...$names, ...(array) __('cardholders.family_fields')];
    }
}
