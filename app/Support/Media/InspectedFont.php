<?php

namespace App\Support\Media;

/**
 * What FontInspector found in a font file.
 */
final readonly class InspectedFont
{
    public function __construct(
        /** truetype, opentype, woff or woff2 */
        public string $format,
        /** Family name from the font's own name table, when it could be read. */
        public ?string $family,
    ) {}

    public function extension(): string
    {
        return FontInspector::EXTENSIONS[$this->format];
    }

    public function mimeType(): string
    {
        return FontInspector::MIME_TYPES[$this->format];
    }
}
