<?php

namespace App\Support\Pdf;

/**
 * How a page is printed to PDF. Page size and margins come from the page's own CSS @page rules, apart
 * from the room a footer needs.
 */
final readonly class PdfOptions
{
    public function __construct(
        /** JavaScript expression that becomes true once the page has finished drawing. */
        public ?string $waitForExpression = null,
        public bool $printBackground = true,
        /**
         * HTML printed at the foot of every page (Chromium's footer template: elements with the classes
         * pageNumber, totalPages, date, title are filled in); null for none.
         */
        public ?string $footerHtml = null,
        /** Space below the page's own content for the footer, e.g. "12mm" (the page's CSS sets the rest). */
        public string $marginBottom = '0',
    ) {}
}
