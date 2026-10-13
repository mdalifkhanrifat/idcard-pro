<?php

namespace App\Services\Reports\Definitions;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Data\Signatories\SignatoryFilter;
use App\Models\Signatory;
use App\Models\User;
use App\Repositories\Contracts\SignatoryRepositoryInterface;
use App\Services\Reports\Concerns\DescribesColumns;
use App\Services\Reports\Report;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * Signatories (legacy "Authorized Signature" list: name, designation, authority type, status), with when each
 * signs and whether they sign cards today, in the organization's days.
 *
 * @implements Report<Signatory>
 */
class SignatoryReport implements Report
{
    use DescribesColumns;

    public function __construct(private SignatoryRepositoryInterface $signatories) {}

    public function key(): string
    {
        return 'signatories';
    }

    public function allows(User $user): bool
    {
        return $user->can('signatories.view');
    }

    public function columns(): array
    {
        $timezone = $this->timezone();
        $today = app(TenantContext::class)->today()->toDateString();
        $yesNo = fn (bool $value) => (string) __('reports.values.'.($value ? 'yes' : 'no'));
        $column = fn (string $key, string $type, \Closure $value) => [$key => $this->column($key, $type, 'signatory', $value)];

        return [
            ...$column('signatory.name', ReportColumn::TEXT, fn (Signatory $signatory) => $signatory->name),
            ...$column('signatory.name_local', ReportColumn::TEXT, fn (Signatory $signatory) => $signatory->name_local),
            ...$column('signatory.designation', ReportColumn::TEXT, fn (Signatory $signatory) => $signatory->designation),
            ...$column('signatory.designation_local', ReportColumn::TEXT, fn (Signatory $signatory) => $signatory->designation_local),
            ...$column('signatory.authority_type', ReportColumn::TEXT, fn (Signatory $signatory) => $signatory->authorityType?->name),
            ...$column('signatory.effective_from', ReportColumn::DATE, fn (Signatory $signatory) => $signatory->effective_from),
            ...$column('signatory.effective_to', ReportColumn::DATE, fn (Signatory $signatory) => $signatory->effective_to),
            // Signs cards issued today: active, and today within the dates
            ...$column('signatory.in_effect', ReportColumn::TEXT, fn (Signatory $signatory) => $yesNo($signatory->is_active
                && ($signatory->effective_from === null || $signatory->effective_from->toDateString() <= $today)
                && ($signatory->effective_to === null || $signatory->effective_to->toDateString() >= $today))),
            ...$column('signatory.has_signature', ReportColumn::TEXT, fn (Signatory $signatory) => $yesNo(filled($signatory->signature_path))),
            ...$column('signatory.status', ReportColumn::TEXT, fn (Signatory $signatory) => (string) __('reports.values.user_status.'.($signatory->is_active ? 'active' : 'inactive'))),
            ...$column('signatory.created_at', ReportColumn::DATETIME, fn (Signatory $signatory) => $signatory->created_at?->setTimezone($timezone)),
        ];
    }

    public function defaultColumns(): array
    {
        // Legacy order: name, designation, authority type, status, created; then the dates and the signature
        return ['signatory.name', 'signatory.designation', 'signatory.authority_type', 'signatory.status', 'signatory.created_at', 'signatory.effective_from', 'signatory.effective_to', 'signatory.in_effect', 'signatory.has_signature'];
    }

    public function filters(): array
    {
        return [
            new ReportFilterField('search', ReportFilterField::SEARCH, (string) __('reports.filters.search_signatories')),
            new ReportFilterField('is_active', ReportFilterField::SELECT, $this->columnLabel('signatory.status'), [
                ['value' => '1', 'label' => (string) __('reports.values.user_status.active')],
                ['value' => '0', 'label' => (string) __('reports.values.user_status.inactive')],
            ]),
            new ReportFilterField('authority_type', ReportFilterField::MASTER_DATA, $this->columnLabel('signatory.authority_type'), masterData: 'authority_type'),
        ];
    }

    public function sorts(): array
    {
        return array_combine(array_map(fn (string $sort) => "signatory.{$sort}", SignatoryFilter::SORTS), SignatoryFilter::SORTS);
    }

    public function defaultSort(): string
    {
        return 'name';
    }

    public function totalKeys(): array
    {
        return ['signatories', 'active'];
    }

    public function paginate(ReportQuery $query): LengthAwarePaginator
    {
        return $this->signatories->report(SignatoryFilter::fromArray($query->input()));
    }

    public function rows(ReportQuery $query): LazyCollection
    {
        return $this->signatories->reportRows(SignatoryFilter::fromArray($query->input()));
    }

    public function count(ReportQuery $query): int
    {
        return $this->totals($query)['signatories'];
    }

    public function totals(ReportQuery $query): array
    {
        return $this->signatories->reportTotals(SignatoryFilter::fromArray($query->input()));
    }

    /**
     * @param  Signatory  $row
     */
    public function present(Model $row): array
    {
        return ['id' => $row->public_id, 'name' => $row->name, 'designation' => $row->designation];
    }
}
