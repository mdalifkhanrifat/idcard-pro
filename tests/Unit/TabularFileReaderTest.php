<?php

use App\Support\Spreadsheets\TabularFileReader;
use App\Support\Spreadsheets\UnreadableSpreadsheetException;

function tempFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'tab');
    file_put_contents($path, $contents);

    return $path;
}

/**
 * Build a minimal XLSX workbook with shared strings, inline strings, numbers and a gap.
 */
function xlsxFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="People" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/data.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Name</t></si><si><t>Employee no</t></si><si><r><t>Rahim </t></r><r><t>Uddin</t></r></si></sst>');
    $zip->addFromString('xl/worksheets/data.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="inlineStr"><is><t>Joined</t></is></c></row>'
        .'<row r="2"><c r="A2" t="s"><v>2</v></c><c r="C2"><v>45292</v></c></row>'
        .'</sheetData></worksheet>');
    $zip->close();

    return $path;
}

it('reads csv with a byte order mark and quoted cells', function () {
    $rows = iterator_to_array((new TabularFileReader)->rows(tempFile("\xEF\xBB\xBFName,Phone\n\"Uddin, Rahim\", 0171 \n"), 'csv'));

    expect($rows)->toBe([1 => ['Name', 'Phone'], 2 => ['Uddin, Rahim', '0171']]);
});

it('detects semicolon separated csv', function () {
    $rows = iterator_to_array((new TabularFileReader)->rows(tempFile("Name;Phone\nRahim;0171\n"), 'csv'));

    expect($rows[2])->toBe(['Rahim', '0171']);
});

it('reads the first worksheet of an xlsx workbook', function () {
    $rows = iterator_to_array((new TabularFileReader)->rows(xlsxFile(), 'xlsx'));

    expect($rows)->toBe([
        1 => ['Name', 'Employee no', 'Joined'],
        2 => ['Rahim Uddin', '', '45292'],
    ]);
});

it('rejects files it cannot read', function () {
    iterator_to_array((new TabularFileReader)->rows(tempFile('not a zip'), 'xlsx'));
})->throws(UnreadableSpreadsheetException::class);
