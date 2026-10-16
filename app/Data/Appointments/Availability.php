<?php

namespace App\Data\Appointments;

/**
 * What a place has free for one kind of visit over some days: per day whether the place works then (not on a
 * closed weekday, nor a closure — given), and the time slots that can still be booked, or the day's places
 * (none left to book once it is too late). When nothing can be booked at all, why (`unavailable`: a key of
 * lang appointments.availability.unavailable) and no days.
 *
 * @phpstan-type Slot array{start: string, end: string, capacity: int, booked: int, available: int}
 * @phpstan-type Day array{date: string, open: bool, closure: array{id: string, reason: string|null, reason_local: string|null}|null, slots?: array<int, Slot>, capacity?: int, booked?: int, available?: int}
 */
final readonly class Availability
{
    /**
     * @param  'slot'|'day'  $scheduling
     * @param  array{key: string, name: string, slots: int|null}  $service  the kind of visit, named in the reader's language
     * @param  array<int, Day>  $days
     */
    public function __construct(
        public string $scheduling,
        public array $service,
        public ?string $unavailable,
        public array $days,
        public ?string $from = null,
        public ?string $to = null,
    ) {}
}
