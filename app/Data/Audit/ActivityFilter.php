<?php

namespace App\Data\Audit;

/**
 * Listing criteria for the data change history.
 */
final readonly class ActivityFilter
{
    public function __construct(
        public ?string $logName = null,
        public ?string $event = null,
        public ?string $causerPublicId = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public int $perPage = 25,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request input
     */
    public static function fromArray(array $input): self
    {
        $filter = $input['filter'] ?? [];

        return new self(
            logName: $filter['log_name'] ?? null,
            event: $filter['event'] ?? null,
            causerPublicId: $filter['causer'] ?? null,
            dateFrom: $filter['date_from'] ?? null,
            dateTo: $filter['date_to'] ?? null,
            perPage: (int) ($input['per_page'] ?? 25),
        );
    }
}
