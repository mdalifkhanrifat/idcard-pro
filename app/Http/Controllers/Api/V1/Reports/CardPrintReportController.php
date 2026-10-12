<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\CardPrintReportRequest;
use App\Http\Resources\CardPrintReportResource;
use App\Services\Reports\CardPrintReportService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Card print history report (legacy "Card Print History").
 */
class CardPrintReportController extends Controller
{
    public function __construct(private CardPrintReportService $report) {}

    /**
     * Printings the filter finds, newest first; `meta.totals` counts printings, copies and distinct cards
     * across every page.
     */
    public function index(CardPrintReportRequest $request): AnonymousResourceCollection
    {
        $filter = $request->toFilter();

        return CardPrintReportResource::collection($this->report->list($filter))
            ->additional(['meta' => ['totals' => $this->report->totals($filter)]]);
    }
}
