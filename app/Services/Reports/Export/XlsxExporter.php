<?php

namespace App\Services\Reports\Export;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportDocument;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel workbook (openspout, written row by row so large reports use little memory): bold header row that stays
 * in view, a filter on every column, real dates and numbers, and text always as text (never run as a formula).
 */
class XlsxExporter implements ReportExporter
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
        return 'xlsx';
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function write(string $path, ReportDocument $document, array $columns, iterable $rows): void
    {
        $application = (string) config('app.name');
        $writer = new Writer(new Options(properties: new Properties(
            title: $document->title,
            subject: $document->organization,
            application: $application,
            creator: $document->author,
            lastModifiedBy: $document->author,
        )));
        $writer->openToFile($path);
        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName($this->sheetName($document->title));
            $sheet->setSheetView(new SheetView(freezeRow: 2));
            foreach (array_values($columns) as $index => $column) {
                $sheet->setColumnWidth($this->width($column), $index + 1);
            }

            $header = new Style(fontBold: true, backgroundColor: 'EEEEEE');
            $writer->addRow(new Row(array_map(fn (ReportColumn $column) => new StringCell($column->label, $header), $columns)));
            $styles = array_map(fn (ReportColumn $column) => match ($column->type) {
                ReportColumn::DATE => new Style(format: 'yyyy-mm-dd'),
                ReportColumn::DATETIME => new Style(format: 'yyyy-mm-dd hh:mm'),
                default => null,
            }, $columns);

            $count = 0;
            foreach ($rows as $row) {
                $cells = [];
                foreach ($columns as $index => $column) {
                    $cells[] = $this->cell($column->valueOf($row), $styles[$index]);
                }
                $writer->addRow(new Row($cells));
                $count++;
            }
            if ($columns !== []) {
                $sheet->setAutoFilter(new AutoFilter(0, 1, count($columns) - 1, $count + 1));
            }
        } finally {
            $writer->close();
        }
    }

    private function cell(string|int|float|DateTimeInterface|null $value, ?Style $style): Cell
    {
        return match (true) {
            $value === null || $value === '' => new EmptyCell(null, $style),
            $value instanceof DateTimeInterface => new DateTimeCell($value, $style),
            is_int($value) || is_float($value) => new NumericCell($value, $style),
            // Built as text on purpose: Cell::fromValue() would turn "=..." into a formula
            default => new StringCell($value, $style),
        };
    }

    private function width(ReportColumn $column): float
    {
        return match ($column->type) {
            ReportColumn::DATE => 12,
            ReportColumn::DATETIME => 17,
            ReportColumn::NUMBER => max(8, mb_strlen($column->label) + 2),
            default => min(40, max(14, mb_strlen($column->label) + 4)),
        };
    }

    /**
     * Excel sheet names: at most 31 characters, none of : \ / ? * [ ].
     */
    private function sheetName(string $title): string
    {
        $name = trim(mb_substr((string) preg_replace('/[:\\\\\/?*\[\]]/u', ' ', $title), 0, 31));

        return $name === '' ? 'Report' : $name;
    }
}
