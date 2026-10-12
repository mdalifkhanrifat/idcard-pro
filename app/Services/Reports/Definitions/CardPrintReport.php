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
use App\Support\Tenancy\TenantContext;
use Closure;
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
        private TenantContext $tenant,
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
        $labels = (array) __('reports.columns');
        $timezone = $this->tenant->get()?->timezone ?: (string) config('app.timezone');
        $column = fn (string $key, string $type, string $group, Closure $value) => new ReportColumn($key, (string) ($labels[$key] ?? $key), $type, $group, $value);

        $columns = [
            $column('printed_at', ReportColumn::DATETIME, 'print', fn (CardPrint $row) => $row->printed_at->setTimezone($timezone)),
            $column('printed_by', ReportColumn::TEXT, 'print', fn (CardPrint $row) => $row->printer?->name),
            $column('method', ReportColumn::TEXT, 'print', fn (CardPrint $row) => (string) __("reports.methods.{$row->method}")),
            $column('copies', ReportColumn::NUMBER, 'print', fn (CardPrint $row) => $row->copies),
            $column('note', ReportColumn::TEXT, 'print', fn (CardPrint $row) => $row->note),
            $column('batch', ReportColumn::TEXT, 'print', fn (CardPrint $row) => $row->batch),
            $column('card.number', ReportColumn::TEXT, 'card', fn (CardPrint $row) => $row->card->number),
            $column('card.reason', ReportColumn::TEXT, 'card', fn (CardPrint $row) => (string) __("cards.reasons.{$row->card->reason}")),
            $column('card.status', ReportColumn::TEXT, 'card', fn (CardPrint $row) => (string) __("reports.statuses.{$row->card->status->value}")),
            $column('card.issue_date', ReportColumn::DATE, 'card', fn (CardPrint $row) => $row->card->issue_date),
            $column('card.expiry_date', ReportColumn::DATE, 'card', fn (CardPrint $row) => $row->card->expiry_date),
            $column('card.template', ReportColumn::TEXT, 'card', fn (CardPrint $row) => $row->card->template->name),
            // Who the card is for: the family member on a family card, else the cardholder
            $column('holder.name', ReportColumn::TEXT, 'holder', fn (CardPrint $row) => $row->card->familyMember->name ?? $row->card->cardholder->full_name),
            $column('family.relation', ReportColumn::TEXT, 'holder', fn (CardPrint $row) => ($relation = $row->card->familyMember?->relation) ? (string) __("cards.values.relation.{$relation->value}") : null),
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
