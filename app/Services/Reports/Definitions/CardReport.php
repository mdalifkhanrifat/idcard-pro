<?php

namespace App\Services\Reports\Definitions;

use App\Data\Cards\CardFilter;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\User;
use App\Repositories\Contracts\CardRepositoryInterface;
use App\Services\Reports\Columns\CardholderColumns;
use App\Services\Reports\Concerns\DescribesCards;
use App\Services\Reports\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * Issued cards: the card list with the list's own criteria (status, validity, printed or not, reason, template,
 * holder, issue / print / record dates), so a download holds what the list shows, and by any master data or
 * custom field of the cardholder.
 *
 * @implements Report<Card>
 */
class CardReport implements Report
{
    use DescribesCards;

    public function __construct(
        private CardholderColumns $cardholderColumns,
        private CardRepositoryInterface $cards,
    ) {}

    public function key(): string
    {
        return 'cards';
    }

    public function allows(User $user): bool
    {
        return $user->can('cards.view');
    }

    public function columns(): array
    {
        return [
            ...collect($this->cardColumns(fn (Card $card) => $card))->keyBy('key')->all(),
            ...$this->cardholderColumns->for(fn (Card $card) => $card->cardholder),
        ];
    }

    public function defaultColumns(): array
    {
        return ['holder.name', 'cardholder.employee_no', 'card.number', 'card.reason', 'card.status', 'card.template', 'card.issue_date', 'card.expiry_date', 'card.print_count', 'card.last_printed_at'];
    }

    public function filters(): array
    {
        $options = fn (string $group, array $values) => array_map(fn (string $value) => ['value' => $value, 'label' => (string) __("reports.{$group}.{$value}")], $values);

        return [
            new ReportFilterField('search', ReportFilterField::SEARCH, (string) __('reports.filters.search_cards')),
            new ReportFilterField('status', ReportFilterField::SELECT, $this->columnLabel('card.status'), $options('statuses', array_map(fn (CardStatus $status) => $status->value, CardStatus::cases()))),
            new ReportFilterField('validity', ReportFilterField::SELECT, (string) __('reports.filters.validity'), $options('filters.validities', CardFilter::VALIDITY)),
            new ReportFilterField('expiring_days', ReportFilterField::NUMBER, (string) __('reports.filters.expiring_days'), min: 1, max: 365, default: '30', when: ['validity' => 'expiring']),
            new ReportFilterField('printed', ReportFilterField::SELECT, (string) __('reports.filters.printed'), $options('values.card_state', CardFilter::PRINTED)),
            $this->reasonFilter(),
            $this->templateFilter(),
            $this->holderFilter(),
            new ReportFilterField('issued_from', ReportFilterField::DATE, (string) __('reports.filters.issued_from')),
            new ReportFilterField('issued_to', ReportFilterField::DATE, (string) __('reports.filters.issued_to'), after: 'issued_from'),
            new ReportFilterField('printed_from', ReportFilterField::DATE, (string) __('reports.filters.printed_from')),
            new ReportFilterField('printed_to', ReportFilterField::DATE, (string) __('reports.filters.printed_to'), after: 'printed_from'),
            new ReportFilterField('created_from', ReportFilterField::DATE, (string) __('reports.filters.created_from')),
            new ReportFilterField('created_to', ReportFilterField::DATE, (string) __('reports.filters.created_to'), after: 'created_from'),
            ...$this->lookupFilters(),
            ...$this->customFieldFilters(),
        ];
    }

    public function sorts(): array
    {
        // The list's own orders
        return [
            'card.issue_date' => 'issue_date',
            'card.expiry_date' => 'expiry_date',
            'card.number' => 'number',
            'card.created_at' => 'created_at',
            'card.last_printed_at' => 'last_printed_at',
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function totalKeys(): array
    {
        return ['cards', 'cardholders', 'family_cards'];
    }

    public function paginate(ReportQuery $query): LengthAwarePaginator
    {
        return $this->cards->report($this->filter($query));
    }

    public function rows(ReportQuery $query): LazyCollection
    {
        return $this->cards->reportRows($this->filter($query));
    }

    public function count(ReportQuery $query): int
    {
        return $this->totals($query)['cards'];
    }

    public function totals(ReportQuery $query): array
    {
        return $this->cards->reportTotals($this->filter($query));
    }

    /**
     * @param  Card  $row
     */
    public function present(Model $row): array
    {
        return [
            'id' => $row->public_id,
            'card' => $this->presentCard($row),
            'family_member' => $this->presentFamilyMember($row->familyMember),
            'cardholder' => $this->presentCardholder($row->cardholder),
        ];
    }

    /**
     * The card list's own reading of the criteria.
     */
    private function filter(ReportQuery $query): CardFilter
    {
        return CardFilter::fromArray($query->input());
    }
}
