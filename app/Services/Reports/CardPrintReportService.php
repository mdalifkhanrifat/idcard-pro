<?php

namespace App\Services\Reports;

use App\Data\Reports\CardPrintReportFilter;
use App\Models\CardPrint;
use App\Repositories\Contracts\CardPrintRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Card print history report (legacy "Card Print History"): every printing of every card, who printed it,
 * how and how many copies, with the card and its holder, between two of the organization's days.
 */
class CardPrintReportService
{
    public function __construct(private CardPrintRepositoryInterface $prints) {}

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
