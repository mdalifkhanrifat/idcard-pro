<?php

namespace App\Data\Reports;

use Illuminate\Support\Arr;

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
     * The query as the report's own request input (filter, sort, per_page), e.g. for its filter object; dotted
     * filters nested again (custom.<field> => custom[<field>]), as a list's request has them.
     *
     * @return array{filter: array<string, mixed>, sort: string, per_page: int}
     */
    public function input(): array
    {
        return ['filter' => Arr::undot($this->filter), 'sort' => $this->sort, 'per_page' => $this->perPage];
    }
}
