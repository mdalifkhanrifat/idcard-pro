<?php

namespace App\Http\Requests\Reports;

use App\Data\Reports\CardPrintReportFilter;
use App\Models\CardPrint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CardPrintReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reports.view');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $sorts = collect(CardPrintReportFilter::SORTS)->flatMap(fn (string $s) => [$s, "-{$s}"])->all();
        $lookupKeys = array_keys(CardPrintReportFilter::lookupFilters());
        $allowed = implode(',', ['search', 'printed_from', 'printed_to', 'template', 'method', 'printed_by', 'batch', 'holder', ...$lookupKeys]);

        return [
            'filter' => ['sometimes', "array:{$allowed}"],
            'filter.search' => ['nullable', 'string', 'max:100'],
            'filter.printed_from' => ['nullable', 'date_format:Y-m-d'],
            'filter.printed_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filter.printed_from'],
            'filter.template' => ['nullable', 'string', 'size:26'],
            'filter.method' => ['nullable', Rule::in([...(array) config('cards.print.methods'), CardPrint::METHOD_IMPORT])],
            'filter.printed_by' => ['nullable', 'string', 'size:26'],
            'filter.batch' => ['nullable', 'string', 'size:26'],
            'filter.holder' => ['nullable', Rule::in(['cardholder', 'family'])],
            ...collect($lookupKeys)->mapWithKeys(fn (string $key) => ["filter.{$key}" => ['nullable', 'string', 'size:26']])->all(),
            'sort' => ['sometimes', Rule::in($sorts)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function toFilter(): CardPrintReportFilter
    {
        return CardPrintReportFilter::fromArray($this->validated());
    }
}
