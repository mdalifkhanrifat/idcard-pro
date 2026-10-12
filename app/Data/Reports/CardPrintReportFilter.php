<?php

namespace App\Data\Reports;

use App\Models\Cardholder;

/**
 * Criteria of the card print history report (legacy "Card Print History"): every printing of every card.
 */
final readonly class CardPrintReportFilter
{
    public const SORTS = ['printed_at', 'copies'];

    /**
     * @param  array<string, string>  $lookups  cardholder column => master data public id (any of Cardholder::LOOKUPS)
     */
    public function __construct(
        /** Card number, RF number, cardholder name or number, family member name. */
        public ?string $search = null,
        /** Printed on or after this day (the organization's days). */
        public ?string $printedFrom = null,
        /** Printed on or before this day, the whole day included. */
        public ?string $printedTo = null,
        public ?string $templatePublicId = null,
        public ?string $method = null,
        /** Public id of the user who printed. */
        public ?string $printedBy = null,
        public ?string $batch = null,
        /** cardholder or family (family member cards). */
        public ?string $holder = null,
        public array $lookups = [],
        public string $sort = 'printed_at',
        public bool $descending = true,
        public int $perPage = 15,
    ) {}

    /**
     * Filter keys of the cardholder's master data (e.g. "rank" => "rank_id").
     *
     * @return array<string, string>
     */
    public static function lookupFilters(): array
    {
        return array_flip(Cardholder::LOOKUPS);
    }

    /**
     * @param  array<string, mixed>  $input  validated request input
     */
    public static function fromArray(array $input): self
    {
        $filter = $input['filter'] ?? [];
        $sort = (string) ($input['sort'] ?? '-printed_at');

        $lookups = [];
        foreach (self::lookupFilters() as $key => $column) {
            if (filled($filter[$key] ?? null)) {
                $lookups[$column] = (string) $filter[$key];
            }
        }

        return new self(
            search: $filter['search'] ?? null,
            printedFrom: $filter['printed_from'] ?? null,
            printedTo: $filter['printed_to'] ?? null,
            templatePublicId: $filter['template'] ?? null,
            method: $filter['method'] ?? null,
            printedBy: $filter['printed_by'] ?? null,
            batch: $filter['batch'] ?? null,
            holder: $filter['holder'] ?? null,
            lookups: $lookups,
            sort: ltrim($sort, '-'),
            descending: str_starts_with($sort, '-'),
            perPage: (int) ($input['per_page'] ?? 15),
        );
    }
}
