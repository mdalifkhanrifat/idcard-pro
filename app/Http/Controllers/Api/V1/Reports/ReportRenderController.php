<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * The page of a report document for the PDF engine (signed link made by PdfExporter); readable once.
 */
class ReportRenderController extends Controller
{
    public function show(string $token): Response
    {
        $html = Cache::pull("report-render:{$token}");
        abort_unless(is_string($html), 404);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
