<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\UpdateReportColumnsRequest;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The report registry: every report with its columns, for building report pages and downloads.
 */
class ReportController extends Controller
{
    public function __construct(
        private ReportService $reports,
        private ReportRegistry $registry,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('reports.view');

        return response()->json(['data' => $this->reports->catalogue($request->user())]);
    }

    /**
     * Columns the report shows by default in this organization (`columns: null` for the report's own default);
     * returns the report as GET /reports lists it.
     */
    public function updateColumns(UpdateReportColumnsRequest $request, string $report): JsonResponse
    {
        $report = $this->registry->get(ReportRegistry::keyOf($report));

        return response()->json(['data' => $this->reports->setDefaultColumns($report, $request->columns(), $request->user())]);
    }
}
