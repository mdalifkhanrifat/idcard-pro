<?php

namespace App\Data\Reports;

/**
 * A report written to a temporary file, ready to download (and to delete once sent).
 */
final readonly class ReportFile
{
    public function __construct(
        public string $path,
        public string $filename,
        public string $contentType,
        public int $rows,
    ) {}
}
