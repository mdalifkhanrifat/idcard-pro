<?php

namespace App\Http\Requests\Reports;

use Illuminate\Validation\Rule;

/**
 * Download of any report: the same filter, sort and columns as its rows, and a format.
 */
class ExportReportRequest extends ReportRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reports.export') && $this->report()->allows($this->user());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'format' => ['required', Rule::in(array_keys((array) config('reports.exporters')))],
        ];
    }
}
