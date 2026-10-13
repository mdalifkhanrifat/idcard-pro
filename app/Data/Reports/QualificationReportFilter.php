<?php

namespace App\Data\Reports;

use App\Data\Cardholders\CardholderFilter;

/**
 * Criteria of the education and training report (legacy "Education Info" and "Other Course" lists): records by
 * kind and year, and by the cardholder's master data and custom fields.
 */
final readonly class QualificationReportFilter
{
    /**
     * @param  array<string, string>  $lookups  cardholder column => master data public id
     * @param  array<string, string>  $custom  the cardholder's custom fields: key => exact value
     */
    public function __construct(
        /** Exam or course, institution, subject, degree, result or certificate; cardholder name or number. */
        public ?string $search = null,
        public ?string $kind = null,
        public ?int $yearFrom = null,
        public ?int $yearTo = null,
        public array $lookups = [],
        public array $custom = [],
        public string $sort = 'year',
        public bool $descending = true,
        public int $perPage = 15,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated report input
     */
    public static function fromArray(array $input): self
    {
        $filter = $input['filter'] ?? [];
        $sort = (string) ($input['sort'] ?? '-year');
        $lookups = [];
        foreach (CardholderFilter::lookupFilters() as $key => $column) {
            if (filled($filter[$key] ?? null)) {
                $lookups[$column] = (string) $filter[$key];
            }
        }

        return new self(
            search: $filter['search'] ?? null,
            kind: $filter['kind'] ?? null,
            yearFrom: filled($filter['year_from'] ?? null) ? (int) $filter['year_from'] : null,
            yearTo: filled($filter['year_to'] ?? null) ? (int) $filter['year_to'] : null,
            lookups: $lookups,
            custom: CardholderFilter::customFrom($filter),
            sort: ltrim($sort, '-'),
            descending: str_starts_with($sort, '-'),
            perPage: (int) ($input['per_page'] ?? 15),
        );
    }
}
