<?php

namespace App\Data\Cardholders;

use App\Models\Cardholder;

/**
 * Listing criteria for cardholders.
 */
final readonly class CardholderFilter
{
    public const SORTS = ['full_name', 'employee_no', 'joining_date', 'created_at', 'updated_at'];

    /**
     * The cardholder's own active card: none, pending (never printed, legacy "PENDING") or printed.
     */
    public const CARD_STATES = ['none', 'pending', 'printed'];

    /**
     * Master data filters, one per list a cardholder refers to: filter key (the list) => cardholder column.
     *
     * @return array<string, string>
     */
    public static function lookupFilters(): array
    {
        return array_flip(Cardholder::LOOKUPS);
    }

    /**
     * @param  array<string, string>  $lookups  cardholder column => master data public id
     * @param  array<string, string>  $custom  custom field key => exact value
     */
    public function __construct(
        public ?string $search = null,
        public ?string $status = null,
        public ?string $employmentType = null,
        public array $lookups = [],
        public array $custom = [],
        public ?string $joinedFrom = null,
        public ?string $joinedTo = null,
        public ?string $card = null,
        /** BiometricStatus value; only for those who may see biometrics. */
        public ?string $biometrics = null,
        /** The search also finds card numbers and RF card numbers (legacy list search); only for those who may see cards. */
        public bool $searchCards = false,
        public string $sort = 'created_at',
        public bool $descending = true,
        public int $perPage = 15,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request input
     */
    public static function fromArray(array $input, bool $searchCards = false, bool $biometrics = false): self
    {
        $filter = $input['filter'] ?? [];
        $sort = (string) ($input['sort'] ?? '-created_at');

        $lookups = [];
        foreach (self::lookupFilters() as $key => $column) {
            if (filled($filter[$key] ?? null)) {
                $lookups[$column] = (string) $filter[$key];
            }
        }

        return new self(
            search: $filter['search'] ?? null,
            status: $filter['status'] ?? null,
            employmentType: $filter['employment_type'] ?? null,
            lookups: $lookups,
            custom: array_filter(
                (array) ($filter['custom'] ?? []),
                fn (mixed $value, mixed $key) => is_string($key) && preg_match('/^[a-z][a-z0-9_]*$/', $key) && filled($value),
                ARRAY_FILTER_USE_BOTH,
            ),
            joinedFrom: $filter['joined_from'] ?? null,
            joinedTo: $filter['joined_to'] ?? null,
            card: $filter['card'] ?? null,
            biometrics: $biometrics ? ($filter['biometrics'] ?? null) : null,
            searchCards: $searchCards,
            sort: ltrim($sort, '-'),
            descending: str_starts_with($sort, '-'),
            perPage: (int) ($input['per_page'] ?? 15),
        );
    }
}
