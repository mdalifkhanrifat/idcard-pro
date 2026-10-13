<?php

namespace App\Data\Audit;

/**
 * Listing criteria for the sign-in history.
 */
final readonly class LoginEventFilter
{
    public function __construct(
        public ?string $event = null,
        public ?string $identifier = null,
        public ?string $ipAddress = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public int $perPage = 25,
        /** Newest first, unless the report asks for the oldest first (sort=id). */
        public bool $descending = true,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request input
     */
    public static function fromArray(array $input): self
    {
        $filter = $input['filter'] ?? [];

        return new self(
            event: $filter['event'] ?? null,
            identifier: $filter['identifier'] ?? null,
            ipAddress: $filter['ip_address'] ?? null,
            dateFrom: $filter['date_from'] ?? null,
            dateTo: $filter['date_to'] ?? null,
            perPage: (int) ($input['per_page'] ?? 25),
            descending: ($input['sort'] ?? '-id') !== 'id',
        );
    }
}
