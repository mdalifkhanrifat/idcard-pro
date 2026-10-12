<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportColumn;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenants\TenantService;
use App\Support\Tenancy\TenantContext;

/**
 * What the registry offers a user: each report with every column it can show (labels in the user's
 * language) and the default columns, so a client builds its pages and downloads from this alone.
 */
class ReportService
{
    public function __construct(
        private ReportRegistry $registry,
        private ReportExportService $exports,
        private TenantService $tenants,
        private TenantContext $tenant,
    ) {}

    /**
     * `default_columns` are the organization's choice (or the report's own `standard_columns`); `formats`
     * (download formats) and `max_export_rows` are empty/null without reports.export.
     *
     * @return array<int, array{key: string, name: string, columns: array<int, array{key: string, label: string, type: string, group: string}>, default_columns: array<int, string>, standard_columns: array<int, string>, formats: array<int, string>, max_export_rows: int|null}>
     */
    public function catalogue(User $user): array
    {
        if (! $user->can('reports.view')) {
            return [];
        }

        $canExport = $user->can('reports.export');

        return array_values(array_map(fn (Report $report) => $this->describe($report, $canExport), $this->registry->all()));
    }

    /**
     * The columns a report shows when none were chosen: the organization's choice, without columns that no
     * longer exist (e.g. a custom field turned off), else the report's own default.
     *
     * @param  array<string, ReportColumn>|null  $available  the report's columns, when already built
     * @return array<int, string>
     */
    public function defaultColumns(Report $report, ?array $available = null): array
    {
        $available ??= $report->columns();
        $chosen = array_values(array_filter($this->tenant->get()?->reportColumns($report->key()) ?? [], fn (string $key) => isset($available[$key])));

        return $chosen !== [] ? $chosen : $report->defaultColumns();
    }

    /**
     * Save the columns the organization's report shows by default; null (or the report's own default) goes back
     * to the report's default, so later improvements to it apply.
     *
     * @param  array<int, string>|null  $columns  validated column keys
     * @return array{key: string, name: string, columns: array<int, array{key: string, label: string, type: string, group: string}>, default_columns: array<int, string>, standard_columns: array<int, string>, formats: array<int, string>, max_export_rows: int|null}
     */
    public function setDefaultColumns(Report $report, ?array $columns, User $actor): array
    {
        $tenant = $this->tenant->get();
        assert($tenant instanceof Tenant);
        $columns = $columns === null || array_values($columns) === $report->defaultColumns() ? null : array_values($columns);
        // The organization being worked on is updated in place, so what follows sees the new choice
        $this->tenants->updateReportColumns($tenant, $report->key(), $columns);

        return $this->describe($report, $actor->can('reports.export'));
    }

    /**
     * @return array{key: string, name: string, columns: array<int, array{key: string, label: string, type: string, group: string}>, default_columns: array<int, string>, standard_columns: array<int, string>, formats: array<int, string>, max_export_rows: int|null}
     */
    private function describe(Report $report, bool $canExport): array
    {
        $columns = $report->columns();

        return [
            'key' => $report->key(),
            'name' => (string) __("reports.names.{$report->key()}"),
            'columns' => array_values(array_map(fn (ReportColumn $column) => $column->toArray(), $columns)),
            'default_columns' => $this->defaultColumns($report, $columns),
            'standard_columns' => $report->defaultColumns(),
            'formats' => $canExport ? $this->exports->formats() : [],
            'max_export_rows' => $canExport ? $this->exports->maxRows() : null,
        ];
    }
}
