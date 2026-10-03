<?php

namespace App\Support\Media;

/**
 * An image re-encoded by ImageSanitizer.
 */
final readonly class SanitizedImage
{
    public function __construct(
        public string $contents,
        public string $mimeType,
        public string $extension,
        public int $width,
        public int $height,
    ) {}
}
