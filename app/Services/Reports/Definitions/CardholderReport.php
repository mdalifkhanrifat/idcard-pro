<?php

namespace App\Services\Reports\Definitions;

use App\Data\Cardholders\CardholderFilter;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Enums\BiometricStatus;
use App\Enums\CardholderStatus;
use App\Enums\EmploymentType;
use App\Models\Cardholder;
use App\Models\User;
use App\Repositories\Contracts\CardholderRepositoryInterface;
use App\Services\Reports\Columns\CardholderColumns;
use App\Services\Reports\Concerns\DescribesCardholders;
use App\Services\Reports\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\LazyCollection;

/**
 * The cardholder list (legacy "Personal Info" list with Print / PDF / Excel): the same criteria as the list
 * page, so a download holds what the list shows; every cardholder field, master data and custom field is a
 * column. Card columns and filters only for those who may see cards, biometric ones for those who may see
 * biometrics, as on the list.
 *
 * @implements Report<Cardholder>
 */
class CardholderReport implements Report
{
    use DescribesCardholders;

    public function __construct(
        private CardholderColumns $cardholderColumns,
        private CardholderRepositoryInterface $cardholders,
    ) {}

    public function key(): string
    {
        return 'cardholders';
    }

    public function allows(User $user): bool
    {
        return $user->can('cardholders.view');
    }

    public function columns(): array
    {
        $timezone = $this->timezone();
        $columns = [
            ...$this->cardholderColumns->for(fn (Cardholder $holder) => $holder),
            'cardholder.status' => $this->column('cardholder.status', ReportColumn::TEXT, 'cardholder', fn (Cardholder $holder) => (string) __("reports.values.cardholder_status.{$holder->status->value}")),
            'cardholder.created_at' => $this->column('cardholder.created_at', ReportColumn::DATETIME, 'cardholder', fn (Cardholder $holder) => $holder->created_at?->setTimezone($timezone)),
            'cardholder.created_by' => $this->column('cardholder.created_by', ReportColumn::TEXT, 'cardholder', fn (Cardholder $holder) => $holder->creator?->name),
            'cardholder.updated_at' => $this->column('cardholder.updated_at', ReportColumn::DATETIME, 'cardholder', fn (Cardholder $holder) => $holder->updated_at?->setTimezone($timezone)),
        ];

        if ($this->userCan('cards.view')) {
            $columns += [
                // The person's own current card (legacy "Card ID" and "Status" PENDING / PRINTED)
                'card.number' => $this->column('card.number', ReportColumn::TEXT, 'card', fn (Cardholder $holder) => $holder->currentCard?->number),
                'cardholder.card_state' => $this->column('cardholder.card_state', ReportColumn::TEXT, 'card', fn (Cardholder $holder) => (string) __('reports.values.card_state.'.$this->cardState($holder))),
                'card.last_printed_at' => $this->column('card.last_printed_at', ReportColumn::DATETIME, 'card', fn (Cardholder $holder) => $holder->currentCard?->last_printed_at?->setTimezone($timezone)),
            ];
        }
        if ($this->userCan('biometrics.view')) {
            $columns['cardholder.biometric_status'] = $this->column('cardholder.biometric_status', ReportColumn::TEXT, 'cardholder', fn (Cardholder $holder) => (string) __("reports.values.biometric_status.{$holder->biometric_status->value}"));
        }

        return $columns;
    }

    public function defaultColumns(): array
    {
        // Legacy list order: name, service no, card id, rank, department, birth, blood group, created, by, status
        return array_values(array_intersect(
            ['cardholder.full_name', 'cardholder.employee_no', 'card.number', 'cardholder.rank', 'cardholder.department', 'cardholder.date_of_birth', 'cardholder.blood_group', 'cardholder.created_at', 'cardholder.created_by', 'cardholder.card_state'],
            array_keys($this->columns()),
        ));
    }

    public function filters(): array
    {
        $option = fn (string $group) => fn (\BackedEnum $case) => ['value' => (string) $case->value, 'label' => (string) __("reports.values.{$group}.{$case->value}")];

        return [
            new ReportFilterField('search', ReportFilterField::SEARCH, (string) __($this->userCan('cards.view') ? 'reports.filters.search_cardholders_cards' : 'reports.filters.search_cardholders')),
            new ReportFilterField('status', ReportFilterField::SELECT, $this->columnLabel('cardholder.status'), array_map($option('cardholder_status'), CardholderStatus::cases())),
            new ReportFilterField('employment_type', ReportFilterField::SELECT, (string) __('reports.fields.employment_type'), array_map(
                fn (EmploymentType $type) => ['value' => $type->value, 'label' => (string) __("cards.values.employment_type.{$type->value}")],
                EmploymentType::cases(),
            )),
            new ReportFilterField('joined_from', ReportFilterField::DATE, (string) __('reports.filters.joined_from')),
            new ReportFilterField('joined_to', ReportFilterField::DATE, (string) __('reports.filters.joined_to'), after: 'joined_from'),
            ...($this->userCan('cards.view') ? [new ReportFilterField('card', ReportFilterField::SELECT, $this->columnLabel('cardholder.card_state'), array_map(
                fn (string $state) => ['value' => $state, 'label' => (string) __("reports.values.card_state.{$state}")],
                CardholderFilter::CARD_STATES,
            ))] : []),
            ...($this->userCan('biometrics.view') ? [new ReportFilterField('biometrics', ReportFilterField::SELECT, $this->columnLabel('cardholder.biometric_status'), array_map($option('biometric_status'), BiometricStatus::cases()))] : []),
            ...$this->lookupFilters(),
            ...$this->customFieldFilters(),
        ];
    }

    public function sorts(): array
    {
        return [
            'cardholder.full_name' => 'full_name',
            'cardholder.employee_no' => 'employee_no',
            'cardholder.joining_date' => 'joining_date',
            'cardholder.created_at' => 'created_at',
            'cardholder.updated_at' => 'updated_at',
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function totalKeys(): array
    {
        return $this->userCan('cards.view') ? ['cardholders', 'no_card', 'pending', 'printed'] : ['cardholders'];
    }

    public function paginate(ReportQuery $query): LengthAwarePaginator
    {
        return $this->cardholders->report($this->filter($query));
    }

    public function rows(ReportQuery $query): LazyCollection
    {
        return $this->cardholders->reportRows($this->filter($query));
    }

    public function count(ReportQuery $query): int
    {
        return $this->totals($query)['cardholders'];
    }

    public function totals(ReportQuery $query): array
    {
        return $this->cardholders->reportTotals($this->filter($query));
    }

    /**
     * @param  Cardholder  $row
     */
    public function present(Model $row): array
    {
        return $this->presentCardholder($row);
    }

    /**
     * The list's own reading of the criteria (searching card numbers and biometric status as the list allows).
     */
    private function filter(ReportQuery $query): CardholderFilter
    {
        return CardholderFilter::fromArray($query->input(), searchCards: $this->userCan('cards.view'), biometrics: $this->userCan('biometrics.view'));
    }

    /**
     * Legacy "Status": no card, a card not printed yet (PENDING) or printed (PRINTED).
     */
    private function cardState(Cardholder $holder): string
    {
        return match (true) {
            $holder->currentCard === null => 'none',
            $holder->currentCard->print_count > 0 => 'printed',
            default => 'pending',
        };
    }

    private function userCan(string $permission): bool
    {
        return (bool) Auth::user()?->can($permission);
    }
}
