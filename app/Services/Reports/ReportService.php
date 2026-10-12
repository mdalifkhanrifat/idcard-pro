<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportColumn;
use App\Models\User;

/**
 * What the registry offers a user: each report with every column it can show (labels in the user's
 * language) and the default columns, so a client builds its pages and downloads from this alone.
 */
class ReportService
{
    public function __construct(
        private ReportRegistry $registry,
        private ReportExportService $exports,
    ) {}

    /**
     * `formats` (download formats) and `max_export_rows` are empty/null without reports.export.
     *
     * @return array<int, array{key: string, name: string, columns: array<int, array{key: string, label: string, type: string, group: string}>, default_columns: array<int, string>, formats: array<int, string>, max_export_rows: int|null}>
     */
    public function catalogue(User $user): array
    {
        if (! $user->can('reports.view')) {
            return [];
        }

        $canExport = $user->can('reports.export');

        return array_values(array_map(fn (Report $report) => [
            'key' => $report->key(),
            'name' => (string) __("reports.names.{$report->key()}"),
            'columns' => array_values(array_map(fn (ReportColumn $column) => $column->toArray(), $report->columns())),
            'default_columns' => $report->defaultColumns(),
            'formats' => $canExport ? $this->exports->formats() : [],
            'max_export_rows' => $canExport ? $this->exports->maxRows() : null,
        ], $this->registry->all()));
    }
}
