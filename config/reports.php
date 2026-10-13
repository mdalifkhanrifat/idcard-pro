<?php

use App\Services\Reports\Definitions\CardholderReport;
use App\Services\Reports\Definitions\CardPrintReport;
use App\Services\Reports\Definitions\CardReport;
use App\Services\Reports\Definitions\ExpiredCardReport;
use App\Services\Reports\Definitions\FamilyMemberReport;
use App\Services\Reports\Definitions\MasterDataReport;
use App\Services\Reports\Definitions\QualificationReport;
use App\Services\Reports\Definitions\RoleReport;
use App\Services\Reports\Definitions\UserReport;
use App\Services\Reports\Export\CsvExporter;
use App\Services\Reports\Export\PdfExporter;
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
        // Legacy "Expired Card": current cards past their last day, or expiring within some days
        'expired_cards' => ExpiredCardReport::class,
        // Legacy "Personal Info" list with Print / PDF / Excel: cardholders as the list finds them
        'cardholders' => CardholderReport::class,
        // Issued cards as the card list finds them
        'cards' => CardReport::class,
        // Legacy "Family Info" list: every family member of every cardholder
        'family_members' => FamilyMemberReport::class,
        // Legacy "Education Info" and "Other Course" lists: education and training of every cardholder
        'qualifications' => QualificationReport::class,
        // Legacy "User" and "Admin" lists: the organization's users as the user list finds them
        'users' => UserReport::class,
        // Legacy "Role" list
        'roles' => RoleReport::class,
        // Legacy lists of ranks, branches, units, designations...: one list or every list of master data
        'master_data' => MasterDataReport::class,
    ],

    /*
     * Download formats: format => class implementing App\Services\Reports\Export\ReportExporter.
     * A new format (e.g. ODS) is a new class and a line here.
     */
    'exporters' => [
        'csv' => CsvExporter::class,
        'xlsx' => XlsxExporter::class,
        // A document to read or print (logo, criteria, totals, page numbers), drawn by the local PDF engine
        'pdf' => PdfExporter::class,
    ],

    // Rows one download may hold; for more, narrow the filter (e.g. by date) and download in parts
    'max_export_rows' => (int) env('REPORT_MAX_EXPORT_ROWS', 20000),

    // Rows one PDF may hold (every row is drawn as a page of a document: heavier than a spreadsheet)
    'pdf_max_rows' => (int) env('REPORT_PDF_MAX_ROWS', 2000),

];
