<?php

namespace App\Http\Requests\Cards;

use App\Data\Cards\CardFilter;
use App\Enums\CardStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ListCardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cards.view');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $sorts = collect(CardFilter::SORTS)->flatMap(fn (string $s) => [$s, "-{$s}"])->all();
        $lookupKeys = array_keys(CardFilter::LOOKUP_FILTERS);
        $allowed = implode(',', ['search', 'status', 'reason', 'template', 'holder', 'issued_from', 'issued_to', 'validity', 'expiring_days', 'printed', 'printed_from', 'printed_to', ...$lookupKeys]);

        return [
            'filter' => ['sometimes', "array:{$allowed}"],
            'filter.search' => ['nullable', 'string', 'max:100'],
            'filter.status' => ['nullable', new Enum(CardStatus::class)],
            'filter.reason' => ['nullable', Rule::in(array_keys((array) config('cards.reasons')))],
            'filter.template' => ['nullable', 'string', 'size:26'],
            'filter.holder' => ['nullable', Rule::in(['cardholder', 'family'])],
            'filter.issued_from' => ['nullable', 'date_format:Y-m-d'],
            'filter.issued_to' => ['nullable', 'date_format:Y-m-d'],
            'filter.validity' => ['nullable', Rule::in(CardFilter::VALIDITY)],
            'filter.expiring_days' => ['nullable', 'integer', 'between:1,365'],
            'filter.printed' => ['nullable', Rule::in(CardFilter::PRINTED)],
            'filter.printed_from' => ['nullable', 'date_format:Y-m-d'],
            'filter.printed_to' => ['nullable', 'date_format:Y-m-d'],
            ...collect($lookupKeys)->mapWithKeys(fn (string $key) => ["filter.{$key}" => ['nullable', 'string', 'size:26']])->all(),
            'sort' => ['sometimes', Rule::in($sorts)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function toFilter(): CardFilter
    {
        return CardFilter::fromArray($this->validated());
    }
}
