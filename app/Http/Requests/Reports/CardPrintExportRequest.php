<?php

namespace App\Http\Requests\Reports;

use Illuminate\Validation\Rule;

/**
 * Download of the card print report: the same filter, sort and columns as the list, and a format.
 */
class CardPrintExportRequest extends CardPrintReportRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reports.export');
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

    /**
     * @return array<string, mixed>
     */
    public function filterInput(): array
    {
        return (array) $this->validated('filter', []);
    }
}
