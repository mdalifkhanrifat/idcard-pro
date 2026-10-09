<?php

namespace App\Data\Biometrics;

/**
 * One capture as a station's driver delivered it (image, optional template, quality), already decoded.
 */
final readonly class CaptureData
{
    public function __construct(
        public string $modality,
        public string $position,
        public string $driver,
        public string $image,
        public string $imageFormat,
        public ?string $template = null,
        public ?string $templateFormat = null,
        /** 0-100 as measured by the driver; null when unknown (e.g. an uploaded image). */
        public ?int $quality = null,
        /** Which try this is at the station. */
        public int $attempts = 1,
        /** The operator chose to keep a capture below the minimum quality after the allowed tries. */
        public bool $acceptLowQuality = false,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $dpi = null,
        /** @var array<string, string>|null vendor, model, serial */
        public ?array $device = null,
    ) {}
}
