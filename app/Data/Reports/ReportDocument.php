<?php

namespace App\Data\Reports;

use DateTimeInterface;

/**
 * What a downloaded report file says about itself: its title, whose it is, who made it when, and, for
 * documents meant for people (PDF), what was asked for and what was found.
 */
final readonly class ReportDocument
{
    /**
     * @param  array<int, array{label: string, value: string}>  $filters  the criteria in words
     * @param  array<int, array{label: string, value: int}>  $totals
     */
    public function __construct(
        public string $title,
        public string $organization,
        public string $author,
        public DateTimeInterface $generatedAt,
        public string $description = '',
        /** The organization's logo as a data: URI (no file access needed to draw it). */
        public ?string $logo = null,
        public array $filters = [],
        public array $totals = [],
        public string $locale = 'en',
        public string $timezone = 'UTC',
        /** The organization's brand colour (#rrggbb), for accents. */
        public string $color = '#0f766e',
    ) {}
}
