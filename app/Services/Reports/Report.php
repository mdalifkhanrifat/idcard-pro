<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportColumn;

/**
 * A report in the registry (config/reports.php). Adding a report means adding a class like this and a line
 * there: the list of reports, their columns and the downloads all follow.
 */
interface Report
{
    /**
     * The registry key, e.g. "card_prints".
     */
    public function key(): string;

    /**
     * Every column this report can show, in their natural order, by key.
     *
     * @return array<string, ReportColumn>
     */
    public function columns(): array;

    /**
     * Columns shown when nobody chose any (legacy report's columns first).
     *
     * @return array<int, string>
     */
    public function defaultColumns(): array;
}
