<?php

namespace App\Data\Cards;

/**
 * Listing criteria for issued cards (legacy reports: card print history and expired cards).
 */
final readonly class CardFilter
{
    public const SORTS = ['issue_date', 'expiry_date', 'number', 'created_at', 'last_printed_at'];

    /**
     * pending: never printed (legacy "PENDING"); printed: printed at least once (legacy "PRINTED").
     */
    public const PRINTED = ['pending', 'printed'];

    /**
     * expired: active cards past their expiry; expiring: active cards that expire within `expiringDays`;
     * valid: active cards not expired.
     */
    public const VALIDITY = ['expired', 'expiring', 'valid'];

    /**
     * Cardholder master data filters: filter key => cardholder column.
     */
    public const LOOKUP_FILTERS = ['department' => 'department_id', 'unit' => 'unit_id'];

    /**
     * @param  array<string, string>  $lookups  cardholder column => master data public id
     */
    public function __construct(
        public ?string $search = null,
        public ?string $status = null,
        public ?string $reason = null,
        public ?string $templatePublicId = null,
        /** cardholder or family (family member cards). */
        public ?string $holder = null,
        public ?string $issuedFrom = null,
        public ?string $issuedTo = null,
        public ?string $validity = null,
        public int $expiringDays = 30,
        public ?string $printed = null,
        /** Printed between these dates (the organization's days), any time it was printed. */
        public ?string $printedFrom = null,
        public ?string $printedTo = null,
        public array $lookups = [],
        public string $sort = 'created_at',
        public bool $descending = true,
        public int $perPage = 15,
        /** Expiring on or after / on or before these days. */
        public ?string $expiryFrom = null,
        public ?string $expiryTo = null,
        /** Recorded here on or after / on or before these days (the organization's days; legacy "Create Date"). */
        public ?string $createdFrom = null,
        public ?string $createdTo = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request input
     */
    public static function fromArray(array $input): self
    {
        $filter = $input['filter'] ?? [];
        $sort = (string) ($input['sort'] ?? '-created_at');

        $lookups = [];
        foreach (self::LOOKUP_FILTERS as $key => $column) {
            if (filled($filter[$key] ?? null)) {
                $lookups[$column] = (string) $filter[$key];
            }
        }

        return new self(
            search: $filter['search'] ?? null,
            status: $filter['status'] ?? null,
            reason: $filter['reason'] ?? null,
            templatePublicId: $filter['template'] ?? null,
            holder: $filter['holder'] ?? null,
            issuedFrom: $filter['issued_from'] ?? null,
            issuedTo: $filter['issued_to'] ?? null,
            validity: $filter['validity'] ?? null,
            expiringDays: (int) ($filter['expiring_days'] ?? 30),
            printed: $filter['printed'] ?? null,
            printedFrom: $filter['printed_from'] ?? null,
            printedTo: $filter['printed_to'] ?? null,
            lookups: $lookups,
            sort: ltrim($sort, '-'),
            descending: str_starts_with($sort, '-'),
            perPage: (int) ($input['per_page'] ?? 15),
        );
    }
}
