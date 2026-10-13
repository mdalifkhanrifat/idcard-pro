<?php

namespace App\Services\Reports\Definitions;

use App\Data\Reports\QualificationReportFilter;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Enums\QualificationKind;
use App\Models\Qualification;
use App\Models\User;
use App\Repositories\Contracts\QualificationRepositoryInterface;
use App\Services\Reports\Columns\CardholderColumns;
use App\Services\Reports\Concerns\DescribesCardholders;
use App\Services\Reports\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * Education and training of all cardholders (legacy "Education Info" and "Other Course" lists), one row per
 * record with the cardholder's details; by kind, year, and the cardholder's master data or custom fields.
 *
 * @implements Report<Qualification>
 */
class QualificationReport implements Report
{
    use DescribesCardholders;

    /** Years a filter may ask for. */
    private const YEARS = [1900, 2100];

    public function __construct(
        private CardholderColumns $cardholderColumns,
        private QualificationRepositoryInterface $qualifications,
    ) {}

    public function key(): string
    {
        return 'qualifications';
    }

    public function allows(User $user): bool
    {
        return $user->can('cardholders.view');
    }

    public function columns(): array
    {
        $timezone = $this->timezone();
        $column = fn (string $key, string $type, \Closure $value) => [$key => $this->column($key, $type, 'qualification', $value)];

        return [
            ...$column('qualification.kind', ReportColumn::TEXT, fn (Qualification $record) => (string) __("reports.values.qualification_kind.{$record->kind->value}")),
            ...$column('qualification.title', ReportColumn::TEXT, fn (Qualification $record) => $record->title),
            ...$column('qualification.subject', ReportColumn::TEXT, fn (Qualification $record) => $record->subject),
            ...$column('qualification.degree', ReportColumn::TEXT, fn (Qualification $record) => $record->degree),
            ...$column('qualification.institution', ReportColumn::TEXT, fn (Qualification $record) => $record->institution),
            // A year is not a count: shown as written (2019, not 2,019)
            ...$column('qualification.year', ReportColumn::TEXT, fn (Qualification $record) => $record->year !== null ? (string) $record->year : null),
            ...$column('qualification.result', ReportColumn::TEXT, fn (Qualification $record) => $record->result),
            ...$column('qualification.certificate_no', ReportColumn::TEXT, fn (Qualification $record) => $record->certificate_no),
            ...$column('qualification.created_at', ReportColumn::DATETIME, fn (Qualification $record) => $record->created_at?->setTimezone($timezone)),
            ...$this->cardholderColumns->for(fn (Qualification $record) => $record->cardholder),
        ];
    }

    public function defaultColumns(): array
    {
        return ['cardholder.full_name', 'cardholder.employee_no', 'qualification.kind', 'qualification.title', 'qualification.subject', 'qualification.institution', 'qualification.year', 'qualification.result'];
    }

    public function filters(): array
    {
        [$first, $last] = self::YEARS;

        return [
            new ReportFilterField('search', ReportFilterField::SEARCH, (string) __('reports.filters.search_qualifications')),
            new ReportFilterField('kind', ReportFilterField::SELECT, $this->columnLabel('qualification.kind'), array_map(
                fn (QualificationKind $kind) => ['value' => $kind->value, 'label' => (string) __("reports.values.qualification_kind.{$kind->value}")],
                QualificationKind::cases(),
            )),
            new ReportFilterField('year_from', ReportFilterField::NUMBER, (string) __('reports.filters.year_from'), min: $first, max: $last),
            new ReportFilterField('year_to', ReportFilterField::NUMBER, (string) __('reports.filters.year_to'), min: $first, max: $last, after: 'year_from'),
            ...$this->lookupFilters(),
            ...$this->customFieldFilters(),
        ];
    }

    public function sorts(): array
    {
        return [
            'qualification.year' => 'year',
            'qualification.title' => 'title',
            'qualification.institution' => 'institution',
            'qualification.created_at' => 'created_at',
        ];
    }

    public function defaultSort(): string
    {
        return '-year';
    }

    public function totalKeys(): array
    {
        return ['records', 'cardholders', 'education', 'courses'];
    }

    public function paginate(ReportQuery $query): LengthAwarePaginator
    {
        return $this->qualifications->report($this->filter($query));
    }

    public function rows(ReportQuery $query): LazyCollection
    {
        return $this->qualifications->reportRows($this->filter($query));
    }

    public function count(ReportQuery $query): int
    {
        return $this->totals($query)['records'];
    }

    public function totals(ReportQuery $query): array
    {
        return $this->qualifications->reportTotals($this->filter($query));
    }

    /**
     * @param  Qualification  $row
     */
    public function present(Model $row): array
    {
        return [
            'id' => $row->public_id,
            'kind' => $row->kind->value,
            'title' => $row->title,
            'cardholder' => $this->presentCardholder($row->cardholder),
        ];
    }

    private function filter(ReportQuery $query): QualificationReportFilter
    {
        return QualificationReportFilter::fromArray($query->input());
    }
}
