<?php

namespace App\Services\Reports\Definitions;

use App\Data\Audit\ActivityFilter;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Models\Cardholder;
use App\Models\User;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Services\Audit\AuditChangeDescriber;
use App\Services\Reports\Concerns\DescribesColumns;
use App\Services\Reports\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use Spatie\Activitylog\Models\Activity;

/**
 * Changes in the audit log (legacy "Activity Log" list: module, description, status, created), with the audit
 * page's criteria: who changed what, when, and what changed in words (old → new).
 *
 * @implements Report<Activity>
 */
class AuditChangeReport implements Report
{
    use DescribesColumns;

    public function __construct(private ActivityRepositoryInterface $activities, private AuditChangeDescriber $describer) {}

    public function key(): string
    {
        return 'audit_changes';
    }

    public function allows(User $user): bool
    {
        return $user->can('audit.view');
    }

    public function columns(): array
    {
        $timezone = $this->timezone();
        $column = fn (string $key, string $type, \Closure $value) => [$key => $this->column($key, $type, 'audit', $value)];

        return [
            ...$column('audit.when', ReportColumn::DATETIME, fn (Activity $activity) => $activity->created_at?->setTimezone($timezone)),
            ...$column('audit.who', ReportColumn::TEXT, fn (Activity $activity) => $this->who($activity)),
            ...$column('audit.who_email', ReportColumn::TEXT, fn (Activity $activity) => $activity->causer instanceof User ? $activity->causer->email : null),
            ...$column('audit.module', ReportColumn::TEXT, fn (Activity $activity) => $this->label('audit_logs', $activity->log_name)),
            ...$column('audit.event', ReportColumn::TEXT, fn (Activity $activity) => $this->label('audit_events', $activity->event)),
            ...$column('audit.subject', ReportColumn::TEXT, fn (Activity $activity) => $activity->subject_type ? class_basename($activity->subject_type) : null),
            ...$column('audit.description', ReportColumn::TEXT, fn (Activity $activity) => $activity->description),
            ...$column('audit.changes', ReportColumn::TEXT, fn (Activity $activity) => $this->changes($activity)),
        ];
    }

    public function defaultColumns(): array
    {
        return ['audit.when', 'audit.who', 'audit.module', 'audit.event', 'audit.subject', 'audit.changes'];
    }

    public function filters(): array
    {
        $options = fn (string $group, array $values) => array_map(fn (string $value) => ['value' => $value, 'label' => $this->label($group, $value) ?? $value], $values);

        return [
            new ReportFilterField('log_name', ReportFilterField::SELECT, $this->columnLabel('audit.module'), $options('audit_logs', $this->activities->logNames())),
            new ReportFilterField('event', ReportFilterField::SELECT, $this->columnLabel('audit.event'), $options('audit_events', $this->activities->events())),
            new ReportFilterField('causer', ReportFilterField::USER, $this->columnLabel('audit.who')),
            new ReportFilterField('date_from', ReportFilterField::DATE, (string) __('reports.filters.on_or_after')),
            new ReportFilterField('date_to', ReportFilterField::DATE, (string) __('reports.filters.on_or_before'), after: 'date_from'),
        ];
    }

    public function sorts(): array
    {
        return ['audit.when' => 'id'];
    }

    public function defaultSort(): string
    {
        return '-id';
    }

    public function totalKeys(): array
    {
        return ['changes', 'people'];
    }

    public function paginate(ReportQuery $query): LengthAwarePaginator
    {
        $page = $this->activities->list($this->filter($query));
        $this->describer->prime($page->items());

        return $page;
    }

    /**
     * The records the changes point to are named a chunk at a time (one query per kind of record per chunk).
     */
    public function rows(ReportQuery $query): LazyCollection
    {
        return $this->activities->reportRows($this->filter($query))
            ->chunk(500)
            ->flatMap(function (LazyCollection $chunk): array {
                $this->describer->prime($chunk);

                return $chunk->all();
            });
    }

    public function count(ReportQuery $query): int
    {
        return $this->totals($query)['changes'];
    }

    public function totals(ReportQuery $query): array
    {
        return $this->activities->reportTotals($this->filter($query));
    }

    /**
     * @param  Activity  $row
     */
    public function present(Model $row): array
    {
        return ['id' => $row->getKey(), 'log_name' => $row->log_name, 'event' => $row->event];
    }

    private function filter(ReportQuery $query): ActivityFilter
    {
        return ActivityFilter::fromArray($query->input());
    }

    /**
     * A user by name, an integration account by name and marked as one, a cardholder acting for themself (public
     * booking) by name and employee number, else the system.
     */
    private function who(Activity $activity): string
    {
        $causer = $activity->causer;

        return match (true) {
            $causer instanceof Cardholder => (string) __($causer->employee_no ? 'reports.values.cardholder_self_numbered' : 'reports.values.cardholder_self', ['name' => $causer->full_name, 'number' => $causer->employee_no]),
            $causer instanceof User && $causer->isIntegration() => (string) __('reports.values.integration_actor', ['name' => $causer->name]),
            $causer !== null => (string) $causer->getAttribute('name'),
            default => (string) __('reports.values.system'),
        };
    }

    private function label(string $group, ?string $value): ?string
    {
        return $value === null ? null : (string) (((array) __("reports.values.{$group}"))[$value] ?? $value);
    }

    /**
     * What changed, in words: "Full name: old → new" for a record's fields (named as the audit page names them),
     * else what an event recorded ("report: cardholders; format: csv").
     */
    private function changes(Activity $activity): ?string
    {
        $fields = $this->describer->fields($activity);
        $parts = $fields !== null
            ? array_map(fn (array $field): string => $field['old'] !== null
                ? "{$field['label']}: {$field['old']} → ".($field['new'] ?? '—')
                : "{$field['label']}: {$field['new']}", $fields)
            : array_map(fn (string $key, mixed $value): string => "{$key}: {$this->describer->text($key, $value)}", array_keys($properties = $activity->properties?->toArray() ?? []), $properties);

        return $parts === [] ? null : implode('; ', $parts);
    }
}
