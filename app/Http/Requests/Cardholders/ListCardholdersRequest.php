<?php

namespace App\Http\Requests\Cardholders;

use App\Data\Cardholders\CardholderFilter;
use App\Enums\CardholderStatus;
use App\Enums\EmploymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ListCardholdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cardholders.view');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $sorts = collect(CardholderFilter::SORTS)->flatMap(fn (string $s) => [$s, "-{$s}"])->all();
        $lookupKeys = array_keys(CardholderFilter::LOOKUP_FILTERS);
        $allowed = implode(',', ['search', 'status', 'employment_type', 'joined_from', 'joined_to', ...$lookupKeys]);

        return [
            'filter' => ['sometimes', "array:{$allowed}"],
            'filter.search' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', new Enum(CardholderStatus::class)],
            'filter.employment_type' => ['nullable', new Enum(EmploymentType::class)],
            'filter.joined_from' => ['nullable', 'date_format:Y-m-d'],
            'filter.joined_to' => ['nullable', 'date_format:Y-m-d'],
            ...collect($lookupKeys)->mapWithKeys(fn (string $key) => ["filter.{$key}" => ['nullable', 'string', 'size:26']])->all(),
            'sort' => ['sometimes', Rule::in($sorts)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function toFilter(): CardholderFilter
    {
        return CardholderFilter::fromArray($this->validated());
    }
}
