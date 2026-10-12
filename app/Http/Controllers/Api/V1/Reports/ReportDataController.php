<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ExportReportRequest;
use App\Http\Requests\Reports\ReportRequest;
use App\Http\Resources\ReportRowResource;
use App\Services\Reports\ReportDataService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\AbstractPaginator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Rows and downloads of any report in the registry (GET /reports/{report}, e.g. card-prints).
 */
class ReportDataController extends Controller
{
    public function __construct(private ReportDataService $data) {}

    /**
     * Rows the filter finds, a page at a time; `meta.totals` counts over every page. With `columns[]`, each row
     * also carries those columns' `values`.
     */
    public function index(ReportRequest $request): AnonymousResourceCollection
    {
        $report = $request->report();
        $query = $request->toQuery();
        $columns = $request->columnKeys() === null ? [] : $this->data->columns($report, $request->columnKeys());
        $page = $this->data->list($report, $query);
        assert($page instanceof AbstractPaginator);
        $page->through(fn (Model $row) => new ReportRowResource($row, $report, $columns));

        return ReportRowResource::collection($page)
            ->additional(['meta' => ['totals' => $this->data->totals($report, $query)]]);
    }

    /**
     * The rows the filter finds as a file (`format`: csv, xlsx...), with the chosen `columns[]` or the default
     * ones; every download is kept in the audit log.
     */
    public function export(ExportReportRequest $request): BinaryFileResponse
    {
        $file = $this->data->export($request->report(), $request->toQuery(), $request->columnKeys(), (string) $request->validated('format'), $request->user());

        return response()->download($file->path, $file->filename, ['Content-Type' => $file->contentType])->deleteFileAfterSend();
    }
}
