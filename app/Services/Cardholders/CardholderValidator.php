<?php

namespace App\Services\Cardholders;

use App\Enums\CardholderStatus;
use App\Enums\EmploymentType;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Models\Cardholder;
use App\Models\MasterDataItem;
use App\Services\CustomFields\CustomFieldService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation rules for cardholder input, shared by the API form and the import.
 * Master data references are public ids.
 */
class CardholderValidator
{
    /**
     * Child reference => parent reference it must belong to.
     */
    public const DEPENDENT_LOOKUPS = [
        'unit_id' => 'department_id',
        'sub_region_id' => 'region_id',
    ];

    public function __construct(private CustomFieldService $customFields) {}

    /**
     * @param  Cardholder|null  $cardholder  the record being updated (ignored by unique rules)
     * @return array<string, array<int, mixed>>
     */
    public function rules(?Cardholder $cardholder = null): array
    {
        $tenantId = app(TenantContext::class)->id();
        $unique = fn (string $column) => Rule::unique('cardholders', $column)->where('tenant_id', $tenantId)->ignore($cardholder?->id);

        $lookups = collect(Cardholder::LOOKUPS)->mapWithKeys(fn (string $type, string $column) => [$column => [
            'nullable', 'string', 'size:26',
            Rule::exists('master_data_items', 'public_id')->where('tenant_id', $tenantId)->where('type', $type),
        ]])->all();

        return [
            // Identity
            'full_name' => ['required', 'string', 'max:255'],
            'full_name_local' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['nullable', new Enum(Gender::class)],
            'marital_status' => ['nullable', new Enum(MaritalStatus::class)],
            'nationality' => ['nullable', 'string', 'max:60'],
            'national_id' => ['nullable', 'string', 'max:40', $unique('national_id')],
            'passport_no' => ['nullable', 'string', 'max:40'],

            // Contact
            'email' => ['nullable', 'email', 'max:255'],
            'personal_phone' => ['nullable', 'string', 'max:30'],
            'emergency_phone' => ['nullable', 'string', 'max:30'],
            'present_address' => ['nullable', 'string', 'max:1000'],
            'permanent_address' => ['nullable', 'string', 'max:1000'],
            'postal_code' => ['nullable', 'string', 'max:20'],

            // Physical description
            'height' => ['nullable', 'string', 'max:20'],
            'weight' => ['nullable', 'string', 'max:20'],
            'eye_color' => ['nullable', 'string', 'max:30'],
            'complexion' => ['nullable', 'string', 'max:30'],
            'identification_mark' => ['nullable', 'string', 'max:255'],

            // Office
            'employee_no' => ['nullable', 'string', 'max:40', $unique('employee_no')],
            'secondary_no' => ['nullable', 'string', 'max:40'],
            'employment_type' => ['sometimes', new Enum(EmploymentType::class)],
            'joining_date' => ['nullable', 'date_format:Y-m-d'],
            'retirement_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:joining_date'],
            'retired_id_no' => ['nullable', 'string', 'max:40'],
            'posting' => ['nullable', 'string', 'max:255'],
            'previous_office' => ['nullable', 'string', 'max:255'],

            // External organization
            'company_name' => ['nullable', 'string', 'max:255'],
            'company_address' => ['nullable', 'string', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:30'],

            ...$lookups,

            'status' => ['sometimes', new Enum(CardholderStatus::class)],

            ...$this->customFields->rules('cardholder'),
        ];
    }

    /**
     * Readable names of custom fields for validation messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->customFields->attributes('cardholder');
    }

    /**
     * Child references (unit, sub-region) that do not belong to the chosen parent.
     * Rule-level errors on either field should be checked first.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string> field => message
     */
    public function parentErrors(array $input, ?Cardholder $cardholder = null): array
    {
        $errors = [];

        foreach (self::DEPENDENT_LOOKUPS as $child => $parent) {
            if (blank($input[$child] ?? null)) {
                continue;
            }

            $parentId = array_key_exists($parent, $input)
                ? $input[$parent]
                : $cardholder?->{Str::camel(Cardholder::LOOKUPS[$parent])}?->public_id;

            $belongs = MasterDataItem::query()
                ->where('public_id', $input[$child])
                ->whereIn('parent_id', MasterDataItem::query()->where('public_id', (string) $parentId)->select('id'))
                ->exists();

            if (! $belongs) {
                $errors[$child] = __('cardholders.lookup_parent_mismatch', ['parent' => __("cardholders.fields.{$parent}")]);
            }
        }

        return $errors;
    }
}
