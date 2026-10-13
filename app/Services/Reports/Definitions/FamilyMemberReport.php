<?php

namespace App\Services\Reports\Definitions;

use App\Data\Reports\FamilyMemberReportFilter;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Models\FamilyMember;
use App\Models\User;
use App\Repositories\Contracts\FamilyMemberRepositoryInterface;
use App\Services\Reports\Columns\CardholderColumns;
use App\Services\Reports\Concerns\DescribesCardholders;
use App\Services\Reports\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * Family members of all cardholders (legacy "Family Info" list: father, mother, spouse, children of each
 * employee), one row per member with the cardholder's details; by relation (e.g. every father), gender and
 * the cardholder's master data or custom fields. The member's own card for those who may see cards.
 *
 * @implements Report<FamilyMember>
 */
class FamilyMemberReport implements Report
{
    use DescribesCardholders;

    public function __construct(
        private CardholderColumns $cardholderColumns,
        private FamilyMemberRepositoryInterface $members,
    ) {}

    public function key(): string
    {
        return 'family_members';
    }

    public function allows(User $user): bool
    {
        return $user->can('cardholders.view');
    }

    public function columns(): array
    {
        $timezone = $this->timezone();
        $column = fn (string $key, string $type, \Closure $value) => [$key => $this->column($key, $type, 'family', $value)];
        $columns = [
            ...$column('family.name', ReportColumn::TEXT, fn (FamilyMember $member) => $member->name),
            ...$column('family.name_local', ReportColumn::TEXT, fn (FamilyMember $member) => $member->name_local),
            ...$column('family.relation', ReportColumn::TEXT, fn (FamilyMember $member) => (string) __("cards.values.relation.{$member->relation->value}")),
            ...$column('family.gender', ReportColumn::TEXT, fn (FamilyMember $member) => $member->gender ? (string) __("cards.values.gender.{$member->gender->value}") : null),
            ...$column('family.date_of_birth', ReportColumn::DATE, fn (FamilyMember $member) => $member->date_of_birth),
            ...$column('family.phone', ReportColumn::TEXT, fn (FamilyMember $member) => $member->phone),
            ...$column('family.blood_group', ReportColumn::TEXT, fn (FamilyMember $member) => $member->bloodGroup?->name),
            ...$column('family.national_id', ReportColumn::TEXT, fn (FamilyMember $member) => $member->national_id),
            ...$column('family.passport_no', ReportColumn::TEXT, fn (FamilyMember $member) => $member->passport_no),
            ...$column('family.identification_mark', ReportColumn::TEXT, fn (FamilyMember $member) => $member->identification_mark),
            ...$column('family.created_at', ReportColumn::DATETIME, fn (FamilyMember $member) => $member->created_at?->setTimezone($timezone)),
        ];
        if ($this->userCan('cards.view')) {
            $columns += [
                ...$column('family.card_number', ReportColumn::TEXT, fn (FamilyMember $member) => $member->currentCard?->number),
                ...$column('family.card_state', ReportColumn::TEXT, fn (FamilyMember $member) => (string) __('reports.values.card_state.'.match (true) {
                    $member->currentCard === null => 'none',
                    $member->currentCard->print_count > 0 => 'printed',
                    default => 'pending',
                })),
            ];
        }
        if ($this->userCan('biometrics.view')) {
            $columns += $column('family.biometric_status', ReportColumn::TEXT, fn (FamilyMember $member) => (string) __("reports.values.biometric_status.{$member->biometric_status->value}"));
        }

        return [...$columns, ...$this->cardholderColumns->for(fn (FamilyMember $member) => $member->cardholder)];
    }

    public function defaultColumns(): array
    {
        return ['cardholder.full_name', 'cardholder.employee_no', 'family.name', 'family.relation', 'family.gender', 'family.date_of_birth', 'family.phone', 'family.created_at'];
    }

    public function filters(): array
    {
        return [
            new ReportFilterField('search', ReportFilterField::SEARCH, (string) __('reports.filters.search_family')),
            new ReportFilterField('relation', ReportFilterField::SELECT, $this->columnLabel('family.relation'), array_map(
                fn (FamilyRelation $relation) => ['value' => $relation->value, 'label' => (string) __("cards.values.relation.{$relation->value}")],
                FamilyRelation::cases(),
            )),
            new ReportFilterField('gender', ReportFilterField::SELECT, $this->columnLabel('family.gender'), array_map(
                fn (Gender $gender) => ['value' => $gender->value, 'label' => (string) __("cards.values.gender.{$gender->value}")],
                Gender::cases(),
            )),
            ...$this->lookupFilters(),
            ...$this->customFieldFilters(),
        ];
    }

    public function sorts(): array
    {
        return [
            'family.name' => 'name',
            'family.date_of_birth' => 'date_of_birth',
            'family.created_at' => 'created_at',
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function totalKeys(): array
    {
        return ['members', 'cardholders'];
    }

    public function paginate(ReportQuery $query): LengthAwarePaginator
    {
        return $this->members->report($this->filter($query));
    }

    public function rows(ReportQuery $query): LazyCollection
    {
        return $this->members->reportRows($this->filter($query));
    }

    public function count(ReportQuery $query): int
    {
        return $this->totals($query)['members'];
    }

    public function totals(ReportQuery $query): array
    {
        return $this->members->reportTotals($this->filter($query));
    }

    /**
     * @param  FamilyMember  $row
     */
    public function present(Model $row): array
    {
        return [
            'id' => $row->public_id,
            'name' => $row->name,
            'relation' => $row->relation->value,
            'cardholder' => $this->presentCardholder($row->cardholder),
        ];
    }

    private function filter(ReportQuery $query): FamilyMemberReportFilter
    {
        return FamilyMemberReportFilter::fromArray($query->input());
    }
}
