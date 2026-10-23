<?php

namespace App\Services\Reports\Definitions;

use App\Data\Appointments\AppointmentFilter;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\User;
use App\Repositories\Contracts\AppointmentLocationRepositoryInterface;
use App\Repositories\Contracts\AppointmentRepositoryInterface;
use App\Services\Appointments\AppointmentService;
use App\Services\Reports\Concerns\DescribesColumns;
use App\Services\Reports\NamesTotals;
use App\Services\Reports\Report;
use App\Services\Reports\ReportValueText;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * Appointments (5.5) with the list's own criteria — the staff's page and a cardholder's tab download what they
 * show: when, who, what and where, how it stands and who booked it. Totals: all, and one per status, named as the
 * statuses are (a new status needs nothing here).
 *
 * @implements Report<Appointment>
 */
class AppointmentReport implements NamesTotals, Report
{
    use DescribesColumns;

    public function __construct(
        private AppointmentRepositoryInterface $appointments,
        private AppointmentLocationRepositoryInterface $locations,
        private AppointmentService $service,
        private ReportValueText $text,
    ) {}

    public function key(): string
    {
        return 'appointments';
    }

    public function allows(User $user): bool
    {
        return $user->can('appointments.view');
    }

    public function columns(): array
    {
        $timezone = $this->timezone();
        $column = fn (string $key, string $type, string $group, \Closure $value) => [$key => $this->column($key, $type, $group, $value)];
        $time = fn (Appointment $a) => $a->starts_at === null
            ? (string) __('reports.values.in_turn')
            : $this->text->digits($a->starts_at->setTimezone($timezone)->format('H:i').($a->ends_at ? '–'.$a->ends_at->setTimezone($timezone)->format('H:i') : ''));

        return [
            ...$column('appointment.date', ReportColumn::DATE, 'appointment', fn (Appointment $a) => $a->date),
            ...$column('appointment.time', ReportColumn::TEXT, 'appointment', $time),
            ...$column('appointment.number', ReportColumn::TEXT, 'appointment', fn (Appointment $a) => $a->number),
            ...$column('appointment.person', ReportColumn::TEXT, 'person', fn (Appointment $a) => $a->familyMember->name ?? $a->cardholder->full_name),
            ...$column('appointment.relation', ReportColumn::TEXT, 'person', fn (Appointment $a) => $a->familyMember ? (string) __("cards.values.relation.{$a->familyMember->relation->value}") : null),
            ...$column('appointment.cardholder', ReportColumn::TEXT, 'person', fn (Appointment $a) => $a->cardholder->full_name),
            ...$column('appointment.employee_no', ReportColumn::TEXT, 'person', fn (Appointment $a) => $a->cardholder->employee_no),
            ...$column('appointment.service', ReportColumn::TEXT, 'appointment', fn (Appointment $a) => $this->serviceNames()[$a->service] ?? $a->service),
            ...$column('appointment.location', ReportColumn::TEXT, 'appointment', fn (Appointment $a) => $a->location->name),
            ...$column('appointment.status', ReportColumn::TEXT, 'appointment', fn (Appointment $a) => (string) __("appointments.statuses.{$a->status->value}")),
            ...$column('appointment.source', ReportColumn::TEXT, 'appointment', fn (Appointment $a) => (string) __("appointments.sources.{$a->source->value}")),
            ...$column('appointment.booked_by', ReportColumn::TEXT, 'appointment', fn (Appointment $a) => $a->bookedBy->name ?? $a->source->actor()),
            ...$column('appointment.created_at', ReportColumn::DATETIME, 'appointment', fn (Appointment $a) => $a->created_at?->setTimezone($timezone)),
            ...$column('appointment.status_changed_by', ReportColumn::TEXT, 'appointment', fn (Appointment $a) => $a->statusChangedBy->name ?? $a->status_changed_via?->actor()),
            ...$column('appointment.status_changed_at', ReportColumn::DATETIME, 'appointment', fn (Appointment $a) => $a->status_changed_at?->setTimezone($timezone)),
            ...$column('appointment.note', ReportColumn::TEXT, 'appointment', fn (Appointment $a) => $a->note),
            ...$column('appointment.cancel_reason', ReportColumn::TEXT, 'appointment', fn (Appointment $a) => $a->cancel_reason),
        ];
    }

    public function defaultColumns(): array
    {
        return ['appointment.date', 'appointment.time', 'appointment.number', 'appointment.person', 'appointment.employee_no', 'appointment.service', 'appointment.location', 'appointment.status'];
    }

    public function filters(): array
    {
        $options = $this->service->options();
        $choices = fn (array $items) => array_map(fn (array $item) => ['value' => $item['key'], 'label' => $item['name']], $items);

        return [
            new ReportFilterField('search', ReportFilterField::SEARCH, (string) __('reports.filters.search_appointments')),
            new ReportFilterField('from', ReportFilterField::DATE, (string) __('reports.filters.on_or_after')),
            new ReportFilterField('to', ReportFilterField::DATE, (string) __('reports.filters.on_or_before'), after: 'from'),
            // Without dates, from the organization's today on (as the list) — or every day before too
            new ReportFilterField('past', ReportFilterField::SELECT, (string) __('reports.filters.days'), [
                ['value' => '0', 'label' => (string) __('reports.filters.day_choices.upcoming')],
                ['value' => '1', 'label' => (string) __('reports.filters.day_choices.all')],
            ], default: '0'),
            new ReportFilterField('location', ReportFilterField::SELECT, $this->columnLabel('appointment.location'), $this->locations->list()->map(fn (AppointmentLocation $place) => ['value' => $place->public_id, 'label' => $place->name])->all()),
            new ReportFilterField('status', ReportFilterField::SELECT, $this->columnLabel('appointment.status'), $choices($options['statuses'])),
            new ReportFilterField('service', ReportFilterField::SELECT, $this->columnLabel('appointment.service'), $choices($options['services'])),
            new ReportFilterField('source', ReportFilterField::SELECT, $this->columnLabel('appointment.source'), $choices($options['sources'])),
            // One cardholder's appointments (theirs and their family members'), from their page
            new ReportFilterField('cardholder', ReportFilterField::CARDHOLDER, (string) __('reports.filters.cardholder')),
        ];
    }

    public function sorts(): array
    {
        return ['appointment.date' => 'date', 'appointment.time' => 'date', 'appointment.number' => 'number', 'appointment.created_at' => 'created_at'];
    }

    public function defaultSort(): string
    {
        return 'date';
    }

    public function totalKeys(): array
    {
        return ['appointments', ...array_map(fn (AppointmentStatus $status) => "status_{$status->value}", AppointmentStatus::cases())];
    }

    public function totalLabel(string $key): ?string
    {
        return str_starts_with($key, 'status_') ? (string) __('appointments.statuses.'.substr($key, 7)) : null;
    }

    public function paginate(ReportQuery $query): LengthAwarePaginator
    {
        $page = $this->appointments->list($this->filter($query));
        // Who took the last step, for its columns (the list itself leaves it out)
        (new EloquentCollection($page->items()))->loadMissing('statusChangedBy');

        return $page;
    }

    public function rows(ReportQuery $query): LazyCollection
    {
        return $this->appointments->reportRows($this->filter($query));
    }

    public function count(ReportQuery $query): int
    {
        return $this->totals($query)['appointments'];
    }

    public function totals(ReportQuery $query): array
    {
        return $this->appointments->reportTotals($this->filter($query));
    }

    /**
     * @param  Appointment  $row
     */
    public function present(Model $row): array
    {
        return ['id' => $row->public_id, 'number' => $row->number, 'date' => $row->date->toDateString(), 'status' => $row->status->value];
    }

    /**
     * The list's own reading of the criteria, so a download holds the list's rows.
     */
    private function filter(ReportQuery $query): AppointmentFilter
    {
        return AppointmentFilter::fromArray($query->input());
    }

    /**
     * @return array<string, string>
     */
    private function serviceNames(): array
    {
        return once(fn () => array_column($this->service->options()['services'], 'name', 'key'));
    }
}
