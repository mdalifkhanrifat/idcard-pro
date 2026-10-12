<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The report registry: every report with its columns, for building report pages and downloads.
 */
class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('reports.view');

        return response()->json(['data' => $this->reports->catalogue($request->user())]);
    }
}
