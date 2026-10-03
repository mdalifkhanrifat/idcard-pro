<?php

namespace App\Http\Requests\MasterData;

use App\Data\MasterData\MasterDataFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListMasterDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('master_data.view');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $sorts = collect(MasterDataFilter::SORTS)->flatMap(fn (string $s) => [$s, "-{$s}"])->all();

        return [
            'filter' => ['sometimes', 'array:search,parent,is_active'],
            'filter.search' => ['nullable', 'string', 'max:100'],
            'filter.parent' => ['nullable', 'string', 'size:26'],
            'filter.is_active' => ['nullable', 'in:0,1,true,false'],
            'sort' => ['sometimes', Rule::in($sorts)],
            'per_page' => ['sometimes', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== 'all' && (! ctype_digit((string) $value) || (int) $value < 1)) {
                    $fail(__('validation.integer', ['attribute' => $attribute]));
                }
            }],
        ];
    }

    public function toFilter(): MasterDataFilter
    {
        return MasterDataFilter::fromArray($this->validated());
    }
}
