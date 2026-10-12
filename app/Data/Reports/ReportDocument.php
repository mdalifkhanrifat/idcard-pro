<?php

namespace App\Data\Reports;

use DateTimeInterface;

/**
 * What a downloaded report file says about itself: its title, whose it is and who made it when.
 */
final readonly class ReportDocument
{
    public function __construct(
        public string $title,
        public string $organization,
        public string $author,
        public DateTimeInterface $generatedAt,
    ) {}
}
