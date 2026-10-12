<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportDocument;
use App\Data\Reports\ReportFile;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Reports\Export\ReportExporter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Throwable;

/**
 * Downloads of any report in any registered format (config('reports.exporters')). A download carries personal
 * data out of the system, so each one is kept in the audit log: who, which report, format, columns, filter and
 * how many rows.
 */
class ReportExportService
{
    public function __construct(private TenantContext $tenant) {}

    /**
     * Formats a report can be downloaded in.
     *
     * @return array<int, string>
     */
    public function formats(): array
    {
        return array_keys((array) config('reports.exporters'));
    }

    public function maxRows(): int
    {
        return (int) config('reports.max_export_rows');
    }

    /**
     * @param  array<int, ReportColumn>  $columns
     * @param  iterable<object>  $rows  the report rows, read one by one
     * @param  array<string, mixed>  $filter  the criteria as asked for (kept in the audit log)
     *
     * @throws BusinessRuleException More rows than one download may hold
     */
    public function export(Report $report, array $columns, int $count, iterable $rows, string $format, array $filter, User $actor): ReportFile
    {
        if ($count > $this->maxRows()) {
            throw new BusinessRuleException(__('reports.export.too_many', ['count' => $count, 'max' => $this->maxRows()]));
        }

        $exporter = app((string) config("reports.exporters.{$format}"));
        assert($exporter instanceof ReportExporter);
        $title = (string) __("reports.names.{$report->key()}");
        $now = now()->setTimezone($this->tenant->get()?->timezone ?: (string) config('app.timezone'));

        $path = (string) tempnam(sys_get_temp_dir(), 'report');
        try {
            $exporter->write($path, new ReportDocument($title, (string) $this->tenant->get()?->name, $actor->name, $now), $columns, $rows);
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        }

        activity('reports')
            ->causedBy($actor)
            ->event('exported')
            ->withProperties([
                'report' => $report->key(),
                'format' => $format,
                'rows' => $count,
                'columns' => array_map(fn (ReportColumn $column) => $column->key, $columns),
                'filter' => $filter,
            ])
            ->log('exported');

        return new ReportFile($path, Str::slug($report->key()).'-'.$now->format('Y-m-d-His').'.'.$exporter->extension(), $exporter->contentType(), $count);
    }
}
