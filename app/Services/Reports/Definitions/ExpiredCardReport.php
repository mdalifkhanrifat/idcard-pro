<?php

namespace App\Services\Reports\Definitions;

use App\Data\Cardholders\CardholderFilter;
use App\Data\Cards\CardFilter;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Models\Card;
use App\Models\Cardholder;
use App\Models\User;
use App\Repositories\Contracts\CardRepositoryInterface;
use App\Services\Reports\Columns\CardholderColumns;
use App\Services\Reports\Concerns\DescribesCards;
use App\Services\Reports\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * Expired cards (legacy "Expired Card": Name, Rank, Branch, Service No, Card No, Issue Date, Expiry Date, Create
 * Date): holders' current cards past their last valid day, or, new, those that expire within some days, so they
 * can be renewed in time. Replaced and revoked cards are not counted: they no longer need renewing.
 *
 * @implements Report<Card>
 */
class ExpiredCardReport implements Report
{
    use DescribesCards;

    /** Days ahead "expiring soon" looks when none are given. */
    public const EXPIRING_DAYS = 30;

    public function __construct(
        private CardholderColumns $cardholderColumns,
        private CardRepositoryInterface $cards,
    ) {}

    public function key(): string
    {
        return 'expired_cards';
    }

    public function allows(User $user): bool
    {
        return true;
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
        // Legacy columns in their order, then how long ago it expired
        return ['holder.name', 'cardholder.rank', 'cardholder.branch', 'cardholder.employee_no', 'card.number', 'card.issue_date', 'card.expiry_date', 'card.created_at', 'card.days_overdue'];
    }

    public function filters(): array
    {
        return [
            new ReportFilterField('search', ReportFilterField::SEARCH, (string) __('reports.filters.search_cards')),
            new ReportFilterField('validity', ReportFilterField::SELECT, (string) __('reports.filters.validity'), [
                ['value' => 'expired', 'label' => (string) __('reports.filters.validities.expired')],
                ['value' => 'expiring', 'label' => (string) __('reports.filters.validities.expiring')],
            ], default: 'expired'),
            new ReportFilterField('expiring_days', ReportFilterField::NUMBER, (string) __('reports.filters.expiring_days'), min: 1, max: 365, default: (string) self::EXPIRING_DAYS, when: ['validity' => 'expiring']),
            new ReportFilterField('expiry_from', ReportFilterField::DATE, (string) __('reports.filters.expiry_from')),
            new ReportFilterField('expiry_to', ReportFilterField::DATE, (string) __('reports.filters.expiry_to'), after: 'expiry_from'),
            new ReportFilterField('issued_from', ReportFilterField::DATE, (string) __('reports.filters.issued_from')),
            new ReportFilterField('issued_to', ReportFilterField::DATE, (string) __('reports.filters.issued_to'), after: 'issued_from'),
            // Legacy From / To: when the card was recorded
            new ReportFilterField('created_from', ReportFilterField::DATE, (string) __('reports.filters.created_from')),
            new ReportFilterField('created_to', ReportFilterField::DATE, (string) __('reports.filters.created_to'), after: 'created_from'),
            $this->reasonFilter(),
            $this->templateFilter(),
            $this->holderFilter(),
            ...$this->lookupFilters(),
            ...$this->customFieldFilters(),
        ];
    }

    public function sorts(): array
    {
        return [
            'card.expiry_date' => 'expiry_date',
            'card.issue_date' => 'issue_date',
            'card.number' => 'number',
            'card.created_at' => 'created_at',
            'card.last_printed_at' => 'last_printed_at',
        ];
    }

    public function defaultSort(): string
    {
        // Longest past its last day first (soonest to expire, when looking ahead)
        return 'expiry_date';
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

    private function filter(ReportQuery $query): CardFilter
    {
        $filter = $query->filter;
        $lookups = [];
        foreach (Cardholder::LOOKUPS as $column => $type) {
            if (filled($filter[$type] ?? null)) {
                $lookups[$column] = $filter[$type];
            }
        }
        $sort = $query->sort;

        return new CardFilter(
            search: $filter['search'] ?? null,
            reason: $filter['reason'] ?? null,
            templatePublicId: $filter['template'] ?? null,
            holder: $filter['holder'] ?? null,
            // Only these two: every other card is not this report's business
            validity: ($filter['validity'] ?? 'expired') === 'expiring' ? 'expiring' : 'expired',
            expiringDays: (int) ($filter['expiring_days'] ?? self::EXPIRING_DAYS),
            lookups: $lookups,
            sort: ltrim($sort, '-'),
            descending: str_starts_with($sort, '-'),
            perPage: $query->perPage,
            expiryFrom: $filter['expiry_from'] ?? null,
            expiryTo: $filter['expiry_to'] ?? null,
            issuedFrom: $filter['issued_from'] ?? null,
            issuedTo: $filter['issued_to'] ?? null,
            createdFrom: $filter['created_from'] ?? null,
            createdTo: $filter['created_to'] ?? null,
            custom: CardholderFilter::customFrom($query->input()['filter']),
        );
    }
}
