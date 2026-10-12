<?php

namespace App\Services\Reports\Definitions;

use App\Data\Reports\CardPrintReportFilter;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Models\CardPrint;
use App\Models\User;
use App\Repositories\Contracts\CardPrintRepositoryInterface;
use App\Services\Reports\Columns\CardholderColumns;
use App\Services\Reports\Concerns\DescribesCards;
use App\Services\Reports\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * Card print history (legacy "Card Print History": Name, Card Type, Card ID, Remarks, Created at): one row per
 * printing, with the card, the holder and any cardholder field.
 *
 * @implements Report<CardPrint>
 */
class CardPrintReport implements Report
{
    use DescribesCards;

    public function __construct(
        private CardholderColumns $cardholderColumns,
        private CardPrintRepositoryInterface $prints,
    ) {}

    public function key(): string
    {
        return 'card_prints';
    }

    public function allows(User $user): bool
    {
        return true;
    }

    public function columns(): array
    {
        $timezone = $this->timezone();

        $columns = [
            $this->column('printed_at', ReportColumn::DATETIME, 'print', fn (CardPrint $row) => $row->printed_at->setTimezone($timezone)),
            $this->column('printed_by', ReportColumn::TEXT, 'print', fn (CardPrint $row) => $row->printer?->name),
            $this->column('method', ReportColumn::TEXT, 'print', fn (CardPrint $row) => (string) __("reports.methods.{$row->method}")),
            $this->column('copies', ReportColumn::NUMBER, 'print', fn (CardPrint $row) => $row->copies),
            $this->column('note', ReportColumn::TEXT, 'print', fn (CardPrint $row) => $row->note),
            $this->column('batch', ReportColumn::TEXT, 'print', fn (CardPrint $row) => $row->batch),
            ...$this->cardColumns(fn (CardPrint $row) => $row->card),
        ];

        return [
            ...collect($columns)->keyBy('key')->all(),
            ...$this->cardholderColumns->for(fn (CardPrint $row) => $row->card->cardholder),
        ];
    }

    public function defaultColumns(): array
    {
        // Legacy columns in their order, then who printed and how many copies
        return ['holder.name', 'card.reason', 'card.number', 'note', 'printed_at', 'printed_by', 'copies'];
    }

    public function filters(): array
    {
        $methods = [...(array) config('cards.print.methods'), CardPrint::METHOD_IMPORT];

        return [
            new ReportFilterField('search', ReportFilterField::SEARCH, (string) __('reports.filters.search_cards')),
            new ReportFilterField('printed_from', ReportFilterField::DATE, (string) __('reports.filters.printed_from')),
            new ReportFilterField('printed_to', ReportFilterField::DATE, (string) __('reports.filters.printed_to'), after: 'printed_from'),
            new ReportFilterField('method', ReportFilterField::SELECT, (string) __('reports.columns.method'), array_map(fn (string $method) => ['value' => $method, 'label' => (string) __("reports.methods.{$method}")], $methods)),
            new ReportFilterField('printed_by', ReportFilterField::USER, (string) __('reports.columns.printed_by')),
            new ReportFilterField('batch', ReportFilterField::SEARCH, (string) __('reports.columns.batch')),
            $this->templateFilter(),
            $this->holderFilter(),
            ...$this->lookupFilters(),
        ];
    }

    public function sorts(): array
    {
        return ['printed_at' => 'printed_at', 'copies' => 'copies'];
    }

    public function defaultSort(): string
    {
        return '-printed_at';
    }

    public function totalKeys(): array
    {
        return ['prints', 'copies', 'cards'];
    }

    public function paginate(ReportQuery $query): LengthAwarePaginator
    {
        return $this->prints->report($this->filter($query));
    }

    public function rows(ReportQuery $query): LazyCollection
    {
        return $this->prints->reportRows($this->filter($query));
    }

    public function count(ReportQuery $query): int
    {
        return $this->totals($query)['prints'];
    }

    public function totals(ReportQuery $query): array
    {
        return $this->prints->reportTotals($this->filter($query));
    }

    /**
     * @param  CardPrint  $row
     */
    public function present(Model $row): array
    {
        $card = $row->card;

        return [
            'id' => $row->public_id,
            'batch' => $row->batch,
            'method' => $row->method,
            'copies' => $row->copies,
            'note' => $row->note,
            'printed_by' => $row->printer ? ['id' => $row->printer->public_id, 'name' => $row->printer->name] : null,
            'printed_at' => $row->printed_at,
            'card' => $this->presentCard($card),
            'family_member' => $this->presentFamilyMember($card->familyMember),
            'cardholder' => $this->presentCardholder($card->cardholder),
        ];
    }

    private function filter(ReportQuery $query): CardPrintReportFilter
    {
        return CardPrintReportFilter::fromArray($query->input());
    }
}
