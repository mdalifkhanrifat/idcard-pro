<?php

namespace App\Data\Reports;

use App\Data\Cardholders\CardholderFilter;

/**
 * Criteria of the family members report (legacy "Family Info" list): members by relation and gender, and by
 * the cardholder's master data and custom fields.
 */
final readonly class FamilyMemberReportFilter
{
    /**
     * @param  array<string, string>  $lookups  cardholder column => master data public id
     * @param  array<string, string>  $custom  the cardholder's custom fields: key => exact value
     */
    public function __construct(
        /** Member name, phone or national id; cardholder name or number. */
        public ?string $search = null,
        public ?string $relation = null,
        public ?string $gender = null,
        public array $lookups = [],
        public array $custom = [],
        public string $sort = 'created_at',
        public bool $descending = true,
        public int $perPage = 15,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated report input
     */
    public static function fromArray(array $input): self
    {
        $filter = $input['filter'] ?? [];
        $sort = (string) ($input['sort'] ?? '-created_at');
        $lookups = [];
        foreach (CardholderFilter::lookupFilters() as $key => $column) {
            if (filled($filter[$key] ?? null)) {
                $lookups[$column] = (string) $filter[$key];
            }
        }

        return new self(
            search: $filter['search'] ?? null,
            relation: $filter['relation'] ?? null,
            gender: $filter['gender'] ?? null,
            lookups: $lookups,
            custom: CardholderFilter::customFrom($filter),
            sort: ltrim($sort, '-'),
            descending: str_starts_with($sort, '-'),
            perPage: (int) ($input['per_page'] ?? 15),
        );
    }
}
