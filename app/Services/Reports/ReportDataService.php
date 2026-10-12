<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFile;
use App\Data\Reports\ReportQuery;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * The rows, totals and downloads of any report in the registry.
 */
class ReportDataService
{
    public function __construct(
        private ReportService $reports,
        private ReportExportService $exports,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Model>
     */
    public function list(Report $report, ReportQuery $query): LengthAwarePaginator
    {
        return $report->paginate($query);
    }

    /**
     * @return array<string, int>
     */
    public function totals(Report $report, ReportQuery $query): array
    {
        return $report->totals($query);
    }

    /**
     * @param  array<int, string>|null  $keys
     * @return array<int, ReportColumn>
     */
    public function columns(Report $report, ?array $keys): array
    {
        return $this->reports->columns($report, $keys);
    }

    /**
     * The report as a file (csv, xlsx...) with the chosen columns (or the default ones), kept in the audit log.
     *
     * @param  array<int, string>|null  $columnKeys
     */
    public function export(Report $report, ReportQuery $query, ?array $columnKeys, string $format, User $actor): ReportFile
    {
        return $this->exports->export($report, $this->columns($report, $columnKeys), $report->count($query), $report->rows($query), $format, $query->filter, $actor);
    }
}
