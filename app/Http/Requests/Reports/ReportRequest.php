<?php

namespace App\Http\Requests\Reports;

use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Services\Reports\Report;
use App\Services\Reports\ReportRegistry;
use Illuminate\Foundation\Http\FormRequest;
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
            'filter' => ['sometimes', 'array:'.implode(',', array_map(fn (ReportFilterField $field) => $field->key, $fields))],
            ...collect($fields)->mapWithKeys(fn (ReportFilterField $field) => ["filter.{$field->key}" => $field->rules()])->all(),
            'sort' => ['sometimes', Rule::in($sorts)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            // Columns whose values each row carries (`values`), in this order; any column of GET /reports
            'columns' => ['sometimes', 'array', 'max:100'],
            'columns.*' => ['string', 'distinct', Rule::in(array_keys($report->columns()))],
        ];
    }

    public function toQuery(): ReportQuery
    {
        $filter = array_filter(array_map(strval(...), (array) $this->validated('filter', [])), fn (string $value) => $value !== '');

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
