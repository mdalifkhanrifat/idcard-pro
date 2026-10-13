<?php

namespace App\Http\Requests\Users;

use App\Data\Users\UserFilter;
use App\Enums\UserStatus;
use App\Support\ListPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ListUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users.view');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $sorts = collect(UserFilter::SORTS)->flatMap(fn (string $s) => [$s, "-{$s}"])->all();

        return [
            'filter' => ['sometimes', 'array:search,status,role,two_factor,locked,created_from,created_to'],
            'filter.search' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', new Enum(UserStatus::class)],
            'filter.role' => ['nullable', 'string', 'max:64'],
            'filter.two_factor' => ['nullable', 'in:0,1,true,false'],
            'filter.locked' => ['nullable', 'in:0,1,true,false'],
            'filter.created_from' => ['nullable', 'date_format:Y-m-d'],
            'filter.created_to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['sometimes', Rule::in($sorts)],
            'per_page' => ListPage::rule(),
        ];
    }

    public function toFilter(): UserFilter
    {
        return UserFilter::fromArray($this->validated());
    }
}
