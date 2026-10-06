<?php

namespace App\Support\Pdf;

/**
 * Server PDFs switched off (pdf.driver = null): printing in the browser still works.
 */
class DisabledPdfRenderer implements PdfRenderer
{
    public function renderUrl(string $url, PdfOptions $options = new PdfOptions): string
    {
        throw new PdfRenderException('PDF rendering is not enabled.');
    }
}
