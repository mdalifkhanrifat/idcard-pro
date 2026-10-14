<?php

namespace App\Services\Reports\Export;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportDocument;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer;

/**
 * CSV in UTF-8 with a byte order mark (so spreadsheet programs read Bangla), days as Y-m-d and moments as
 * Y-m-d H:i in the organization's time zone, for people and for other systems alike.
 */
class CsvExporter implements ReportExporter
{
    public function available(): bool
    {
        return true;
    }

    public function maxRows(): ?int
    {
        return null;
    }

    public function maxColumns(): ?int
    {
        return null;
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function contentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    public function write(string $path, ReportDocument $document, array $columns, iterable $rows): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        try {
            $writer->addRow($this->row(array_map(fn (ReportColumn $column) => $column->label, $columns)));
            foreach ($rows as $row) {
                $writer->addRow($this->row(array_map(fn (ReportColumn $column) => $this->text($column, $column->valueOf($row)), $columns)));
            }
        } finally {
            $writer->close();
        }
    }

    private function text(ReportColumn $column, string|int|float|DateTimeInterface|null $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format($column->type === ReportColumn::DATE ? 'Y-m-d' : 'Y-m-d H:i'),
            default => (string) $value,
        };
    }

    /**
     * Every value as text; text a spreadsheet would run as a formula (=, +, -, @ first) gets a leading
     * apostrophe (OWASP CSV injection).
     *
     * @param  array<int, string>  $values
     */
    private function row(array $values): Row
    {
        return new Row(array_map(fn (string $value) => new StringCell(preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'{$value}" : $value, null), $values));
    }
}
