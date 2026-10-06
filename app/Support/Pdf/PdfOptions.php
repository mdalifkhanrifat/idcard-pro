<?php

namespace App\Support\Pdf;

/**
 * How a page is printed to PDF. Page size and margins come from the page's own CSS @page rules.
 */
final readonly class PdfOptions
{
    public function __construct(
        /** JavaScript expression that becomes true once the page has finished drawing. */
        public ?string $waitForExpression = null,
        public bool $printBackground = true,
    ) {}
}
