<?php

namespace App\Http\Requests\Tenants;

use App\Data\Tenants\TenantFilter;
use App\Enums\TenantStatus;
use App\Support\ListPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ListTenantsRequest extends FormRequest
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
        $sorts = collect(TenantFilter::SORTS)->flatMap(fn (string $s) => [$s, "-{$s}"])->all();

        return [
            'filter' => ['sometimes', 'array:name,slug,status'],
            'filter.name' => ['nullable', 'string', 'max:120'],
            'filter.slug' => ['nullable', 'string', 'max:63'],
            'filter.status' => ['nullable', new Enum(TenantStatus::class)],
            'sort' => ['sometimes', Rule::in($sorts)],
            'per_page' => ListPage::rule(),
        ];
    }

    public function toFilter(): TenantFilter
    {
        return TenantFilter::fromArray($this->validated());
    }
}
