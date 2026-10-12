<?php

namespace App\Http\Requests\Reports;

use App\Services\Reports\ReportRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The columns a report shows by default in the organization; `columns: null` goes back to the report's default.
 */
class UpdateReportColumnsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.update');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $report = app(ReportRegistry::class)->get(ReportRegistry::keyOf((string) $this->route('report')));

        return [
            'columns' => ['present', 'nullable', 'array', 'min:1', 'max:100'],
            'columns.*' => ['string', 'distinct', Rule::in(array_keys($report->columns()))],
        ];
    }

    /**
     * @return array<int, string>|null
     */
    public function columns(): ?array
    {
        return $this->validated('columns');
    }
}
