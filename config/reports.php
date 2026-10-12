<?php

use App\Services\Reports\Definitions\CardPrintReport;

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

];
