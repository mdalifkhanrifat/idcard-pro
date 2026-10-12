<?php

namespace App\Http\Requests\Reports;

use App\Services\Reports\ReportExportService;
use Illuminate\Validation\Rule;

/**
 * Download of any report: the same filter, sort and columns as its rows, a format available here, and whether
 * it is opened to print.
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
            'format' => ['required', Rule::in(app(ReportExportService::class)->formats())],
            // Opened to print (shown in the browser) rather than saved: documents only
            'inline' => ['sometimes', 'boolean', 'prohibited_unless:format,pdf'],
        ];
    }

    public function inline(): bool
    {
        return $this->boolean('inline');
    }
}
