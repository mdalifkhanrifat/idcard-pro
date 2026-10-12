<?php

namespace App\Services\Reports;

use App\Data\Reports\CardPrintReportFilter;
use App\Data\Reports\ReportColumn;
use App\Models\CardPrint;
use App\Repositories\Contracts\CardPrintRepositoryInterface;
use App\Services\Reports\Definitions\CardPrintReport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Card print history report (legacy "Card Print History"): every printing of every card, who printed it,
 * how and how many copies, with the card and its holder, between two of the organization's days.
 */
class CardPrintReportService
{
    public function __construct(
        private CardPrintRepositoryInterface $prints,
        private CardPrintReport $report,
    ) {}

    /**
     * Every column the report can show, by key.
     *
     * @return array<string, ReportColumn>
     */
    public function availableColumns(): array
    {
        return $this->report->columns();
    }

    /**
     * The chosen columns in the order given, or the default ones when none were chosen.
     *
     * @param  array<int, string>|null  $keys  validated keys of availableColumns()
     * @return array<int, ReportColumn>
     */
    public function columns(?array $keys): array
    {
        $available = $this->report->columns();
        $chosen = array_filter($keys ?: $this->report->defaultColumns(), fn (string $key) => isset($available[$key]));

        return array_values(array_map(fn (string $key) => $available[$key], $chosen));
    }

    /**
     * @return LengthAwarePaginator<int, CardPrint>
     */
    public function list(CardPrintReportFilter $filter): LengthAwarePaginator
    {
        return $this->prints->report($filter);
    }

    /**
     * @return array{prints: int, copies: int, cards: int}
     */
    public function totals(CardPrintReportFilter $filter): array
    {
        return $this->prints->reportTotals($filter);
    }
}
