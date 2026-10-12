<?php

namespace App\Services\Reports\Export;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportDocument;

/**
 * A file format reports can be downloaded in (config('reports.exporters')): a new format is a class like this
 * and a line there.
 */
interface ReportExporter
{
    public function extension(): string;

    public function contentType(): string;

    /**
     * Write a header row of the columns' labels, then one row per report row.
     *
     * @param  array<int, ReportColumn>  $columns
     * @param  iterable<object>  $rows
     */
    public function write(string $path, ReportDocument $document, array $columns, iterable $rows): void;
}
