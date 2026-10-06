<?php

namespace App\Support\Pdf;

/**
 * Turns a page of this application into a PDF. The page is drawn by a real browser engine, so a PDF
 * matches what the screen shows (fonts, Bengali shaping, rotation). Drivers are chosen in config/pdf.php;
 * a new engine only needs a new implementation.
 */
interface PdfRenderer
{
    /**
     * Render the page at an address the engine can reach (see config pdf.app_url).
     *
     * @return string The PDF bytes
     *
     * @throws PdfRenderException When the engine is unavailable or the page fails
     */
    public function renderUrl(string $url, PdfOptions $options = new PdfOptions): string;
}
