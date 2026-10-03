<?php

namespace App\Support\Spreadsheets;

use XMLReader;
use ZipArchive;

/**
 * Reads rows from CSV files and from the first worksheet of XLSX workbooks,
 * without third-party packages. Every cell is returned as a trimmed string.
 */
class TabularFileReader
{
    /**
     * @return \Generator<int, array<int, string>> row number (1-based) => cells
     *
     * @throws UnreadableSpreadsheetException
     */
    public function rows(string $path, string $extension): \Generator
    {
        return match (strtolower($extension)) {
            'csv', 'txt' => $this->csv($path),
            'xlsx' => $this->xlsx($path),
            default => throw new UnreadableSpreadsheetException("Unsupported file type: {$extension}"),
        };
    }

    /**
     * @return \Generator<int, array<int, string>>
     */
    private function csv(string $path): \Generator
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new UnreadableSpreadsheetException('The file could not be opened.');
        }

        $first = (string) fgets($handle);
        // Spreadsheet programs in some locales export with semicolons.
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($handle);

        $number = 0;
        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $number++;
            if ($number === 1 && isset($cells[0])) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
            }
            yield $number => array_map(fn ($cell) => trim((string) $cell), $cells);
        }
        fclose($handle);
    }

    /**
     * @return \Generator<int, array<int, string>>
     */
    private function xlsx(string $path): \Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new UnreadableSpreadsheetException('The workbook could not be opened.');
        }

        $sheet = $this->firstSheetPath($zip);
        $strings = $this->sharedStrings($zip);
        $xml = $sheet ? $zip->getFromName($sheet) : false;
        $zip->close();

        if ($xml === false) {
            throw new UnreadableSpreadsheetException('The workbook has no worksheet.');
        }

        $reader = new XMLReader;
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $row = null;
        $number = 0;
        $column = 0;
        $type = '';
        $value = '';

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                switch ($reader->localName) {
                    case 'row':
                        $row = [];
                        $number = (int) ($reader->getAttribute('r') ?: $number + 1);
                        $column = 0;
                        if ($reader->isEmptyElement) {
                            yield $number => [];
                            $row = null;
                        }
                        break;
                    case 'c':
                        $reference = (string) $reader->getAttribute('r');
                        $column = $reference !== '' ? $this->columnIndex($reference) : $column + 1;
                        $type = (string) $reader->getAttribute('t');
                        $value = '';
                        if ($reader->isEmptyElement && $row !== null) {
                            $row[$column] = '';
                        }
                        break;
                    case 'v':
                    case 't':
                        $value .= $reader->readString();
                        break;
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                if ($reader->localName === 'c' && $row !== null) {
                    $row[$column] = trim($type === 's' ? ($strings[(int) $value] ?? '') : $value);
                } elseif ($reader->localName === 'row' && $row !== null) {
                    yield $number => $this->fillGaps($row);
                    $row = null;
                }
            }
        }
        $reader->close();
    }

    private function firstSheetPath(ZipArchive $zip): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relations = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $relations === false) {
            return $zip->locateName('xl/worksheets/sheet1.xml') !== false ? 'xl/worksheets/sheet1.xml' : null;
        }

        preg_match('/<(?:\w+:)?sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $sheet);
        preg_match('/<Relationship\b[^>]*Id="'.preg_quote($sheet[1] ?? '', '/').'"[^>]*Target="([^"]+)"/', $relations, $target)
            || preg_match('/<Relationship\b[^>]*Target="([^"]+)"[^>]*Id="'.preg_quote($sheet[1] ?? '', '/').'"/', $relations, $target);

        if (! isset($target[1])) {
            return null;
        }

        return str_starts_with($target[1], '/') ? ltrim($target[1], '/') : 'xl/'.$target[1];
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $reader = new XMLReader;
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $strings = [];
        $current = null;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $current = '';
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't' && $current !== null) {
                $current .= $reader->readString();
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si') {
                $strings[] = (string) $current;
                $current = null;
            }
        }
        $reader->close();

        return $strings;
    }

    /**
     * "C12" => 2 (zero-based column index).
     */
    private function columnIndex(string $reference): int
    {
        $letters = preg_replace('/\d+/', '', strtoupper($reference));
        $index = 0;
        foreach (str_split((string) $letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * @param  array<int, string>  $row
     * @return array<int, string>
     */
    private function fillGaps(array $row): array
    {
        if ($row === []) {
            return [];
        }
        $filled = array_fill(0, max(array_keys($row)) + 1, '');

        return array_replace($filled, $row);
    }
}
