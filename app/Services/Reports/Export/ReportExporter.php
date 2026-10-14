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
    /**
     * Whether this format can be made here (e.g. a PDF needs the PDF engine).
     */
    public function available(): bool;

    /**
     * Rows one file of this format may hold, when fewer than reports.max_export_rows (null: no own limit).
     */
    public function maxRows(): ?int;

    /**
     * Columns one file of this format may show (null: any number, as in a spreadsheet).
     */
    public function maxColumns(): ?int;

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
