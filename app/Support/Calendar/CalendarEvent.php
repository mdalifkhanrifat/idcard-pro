<?php

namespace App\Support\Calendar;

use Carbon\CarbonImmutable;

/**
 * One event for a calendar file (5.7b): the same `uid` and a higher `sequence` update the event a calendar app
 * already holds (moved, cancelled) instead of adding another. A whole day has no times (`start` and `end` null).
 */
final readonly class CalendarEvent
{
    public const CONFIRMED = 'CONFIRMED';

    public const TENTATIVE = 'TENTATIVE';

    public const CANCELLED = 'CANCELLED';

    public function __construct(
        public string $uid,
        public int $sequence,
        public string $summary,
        /** The organization's day (Y-m-d) */
        public string $date,
        public ?CarbonImmutable $start = null,
        public ?CarbonImmutable $end = null,
        public ?string $location = null,
        public ?string $description = null,
        public string $status = self::CONFIRMED,
    ) {}
}
