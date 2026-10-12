<?php

use App\Services\Reports\Definitions\CardPrintReport;
use App\Services\Reports\Export\CsvExporter;
use App\Services\Reports\Export\XlsxExporter;

/*
|--------------------------------------------------------------------------
| Report registry (Phase 5)
|--------------------------------------------------------------------------
|
| Every report: key => class implementing App\Services\Reports\Report. The class
| names its columns (fixed ones, plus every cardholder field, master data type
| and custom field the organization has) and the default ones. A new report is a
| new class and a line here; the list of reports and the downloads follow.
|
*/

return [

    'reports' => [
        // Legacy "Card Print History": every printing of every card
        'card_prints' => CardPrintReport::class,
    ],

    /*
     * Download formats: format => class implementing App\Services\Reports\Export\ReportExporter.
     * A new format (e.g. ODS) is a new class and a line here.
     */
    'exporters' => [
        'csv' => CsvExporter::class,
        'xlsx' => XlsxExporter::class,
    ],

    // Rows one download may hold; for more, narrow the filter (e.g. by date) and download in parts
    'max_export_rows' => (int) env('REPORT_MAX_EXPORT_ROWS', 20000),

];
