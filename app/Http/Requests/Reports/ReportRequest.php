<?php

namespace App\Http\Requests\Reports;

use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Services\Reports\Report;
use App\Services\Reports\ReportRegistry;
use App\Support\ListPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Rows of any report: the rules come from what the report declares (its filters, sorts and columns).
 */
class ReportRequest extends FormRequest
{
    private ?Report $resolved = null;

    public function authorize(): bool
    {
        return $this->user()->can('reports.view') && $this->report()->allows($this->user());
    }

    /**
     * The report of the address ("card-prints"); unknown reports are 404.
     */
    public function report(): Report
    {
        return $this->resolved ??= app(ReportRegistry::class)->get(ReportRegistry::keyOf((string) $this->route('report')));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $report = $this->report();
        $fields = $report->filters();
        $sorts = collect($report->sorts())->unique()->flatMap(fn (string $field) => [$field, "-{$field}"])->all();

        return [
            // A dotted filter (custom.<field>) arrives nested: filter[custom][<field>]
            'filter' => ['sometimes', 'array:'.implode(',', array_unique(array_map(fn (ReportFilterField $field) => strtok($field->key, '.'), $fields)))],
            // Inside a dotted group (custom), only its own filters
            ...collect($fields)->filter(fn (ReportFilterField $field) => str_contains($field->key, '.'))
                ->groupBy(fn (ReportFilterField $field) => strtok($field->key, '.'))
                ->map(fn ($group) => ['nullable', 'array:'.$group->map(fn (ReportFilterField $field) => substr($field->key, strpos($field->key, '.') + 1))->implode(',')])
                ->mapWithKeys(fn (array $rules, string $prefix) => ["filter.{$prefix}" => $rules])
                ->all(),
            ...collect($fields)->mapWithKeys(fn (ReportFilterField $field) => ["filter.{$field->key}" => $field->rules()])->all(),
            'sort' => ['sometimes', Rule::in($sorts)],
            'per_page' => ListPage::rule(),
            // Columns whose values each row carries (`values`), in this order; any column of GET /reports
            'columns' => ['sometimes', 'array', 'max:100'],
            'columns.*' => ['string', 'distinct', Rule::in(array_keys($report->columns()))],
        ];
    }

    /**
     * Messages name each filter as the report labels it (not "filter.expiring days").
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return collect($this->report()->filters())->mapWithKeys(fn (ReportFilterField $field) => ["filter.{$field->key}" => $field->label])->all();
    }

    public function toQuery(): ReportQuery
    {
        // Flat, by the filters' own keys (custom.<field>), as every report reads them
        $filter = array_filter(array_map(strval(...), Arr::dot((array) $this->validated('filter', []))), fn (string $value) => $value !== '');

        return new ReportQuery($filter, (string) $this->validated('sort', $this->report()->defaultSort()), (int) $this->validated('per_page', 15));
    }

    /**
     * @return array<int, string>|null
     */
    public function columnKeys(): ?array
    {
        return $this->validated('columns');
    }
}
