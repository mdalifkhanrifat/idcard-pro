<?php

namespace App\Repositories\Contracts;

use App\Data\Reports\CardPrintReportFilter;
use App\Models\Card;
use App\Models\CardPrint;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * @extends RepositoryInterface<CardPrint>
 */
interface CardPrintRepositoryInterface extends RepositoryInterface
{
    /**
     * Printings of a card, newest first, with who printed it.
     *
     * @return Collection<int, CardPrint>
     */
    public function listFor(Card $card): Collection;

    /**
     * Every printing the report's criteria find, a page at a time, with the card, its holder and who printed it.
     *
     * @return LengthAwarePaginator<int, CardPrint>
     */
    public function report(CardPrintReportFilter $filter): LengthAwarePaginator;

    /**
     * Totals of what the report's criteria find: printings, copies and distinct cards.
     *
     * @return array{prints: int, copies: int, cards: int}
     */
    public function reportTotals(CardPrintReportFilter $filter): array;

    /**
     * Every printing the report's criteria find, in the report's order, read a chunk at a time (for downloads).
     *
     * @return LazyCollection<int, CardPrint>
     */
    public function reportRows(CardPrintReportFilter $filter): LazyCollection;
}
