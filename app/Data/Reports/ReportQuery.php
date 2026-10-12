<?php

namespace App\Data\Reports;

/**
 * What a report is asked for: its validated filter (`filter[key]` => value), the order and the page size.
 */
final readonly class ReportQuery
{
    /**
     * @param  array<string, string>  $filter
     * @param  string  $sort  sort field, "-" first for descending
     */
    public function __construct(
        public array $filter,
        public string $sort,
        public int $perPage = 15,
    ) {}

    /**
     * The query as the report's own request input (filter, sort, per_page), e.g. for its filter object.
     *
     * @return array{filter: array<string, string>, sort: string, per_page: int}
     */
    public function input(): array
    {
        return ['filter' => $this->filter, 'sort' => $this->sort, 'per_page' => $this->perPage];
    }
}
