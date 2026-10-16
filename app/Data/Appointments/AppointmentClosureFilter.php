<?php

namespace App\Data\Appointments;

/**
 * Listing criteria for the days appointments are not taken.
 */
final readonly class AppointmentClosureFilter
{
    public function __construct(
        /** A place's closures: its own and those of every place */
        public ?string $locationPublicId = null,
        /** Closures overlapping these days of the organization (both whole); yearly ones always come */
        public ?string $from = null,
        public ?string $to = null,
        /** Without a range: also those already over (otherwise from the organization's today on) */
        public bool $past = false,
        /** Only those that come every year (true) or only those of given days (false); null: both */
        public ?bool $yearly = null,
        public int $perPage = 25,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request input
     */
    public static function fromArray(array $input): self
    {
        $filter = $input['filter'] ?? [];

        return new self(
            locationPublicId: $filter['location'] ?? null,
            from: $filter['from'] ?? null,
            to: $filter['to'] ?? null,
            past: filter_var($filter['past'] ?? false, FILTER_VALIDATE_BOOL),
            yearly: isset($filter['yearly']) ? filter_var($filter['yearly'], FILTER_VALIDATE_BOOL) : null,
            perPage: (int) ($input['per_page'] ?? 25),
        );
    }
}
