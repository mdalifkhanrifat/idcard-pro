<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\CardPrintExportRequest;
use App\Http\Requests\Reports\CardPrintReportRequest;
use App\Http\Resources\CardPrintReportResource;
use App\Models\CardPrint;
use App\Services\Reports\CardPrintReportService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\AbstractPaginator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Card print history report (legacy "Card Print History").
 */
class CardPrintReportController extends Controller
{
    public function __construct(private CardPrintReportService $report) {}

    /**
     * Printings the filter finds, newest first; `meta.totals` counts printings, copies and distinct cards
     * across every page. With `columns[]`, each row also carries those columns' `values`.
     */
    public function index(CardPrintReportRequest $request): AnonymousResourceCollection
    {
        $filter = $request->toFilter();
        $columns = $request->columnKeys() === null ? [] : $this->report->columns($request->columnKeys());
        $page = $this->report->list($filter);
        assert($page instanceof AbstractPaginator);
        $page->through(fn (CardPrint $print) => new CardPrintReportResource($print, $columns));

        return CardPrintReportResource::collection($page)
            ->additional(['meta' => ['totals' => $this->report->totals($filter)]]);
    }

    /**
     * The printings the filter finds as a file (`format`: csv, xlsx...), with the chosen `columns[]` or the
     * default ones; every download is kept in the audit log.
     */
    public function export(CardPrintExportRequest $request): BinaryFileResponse
    {
        $file = $this->report->export($request->toFilter(), $request->columnKeys(), (string) $request->validated('format'), $request->filterInput(), $request->user());

        return response()->download($file->path, $file->filename, ['Content-Type' => $file->contentType])->deleteFileAfterSend();
    }
}
