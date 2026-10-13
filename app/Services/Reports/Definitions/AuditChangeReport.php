<?php

namespace App\Services\Reports\Definitions;

use App\Data\Audit\ActivityFilter;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Models\User;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Services\Reports\Concerns\DescribesColumns;
use App\Services\Reports\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
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

    public function __construct(private ActivityRepositoryInterface $activities) {}

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
            ...$column('audit.who', ReportColumn::TEXT, fn (Activity $activity) => $activity->causer?->getAttribute('name') ?? (string) __('reports.values.system')),
            ...$column('audit.who_email', ReportColumn::TEXT, fn (Activity $activity) => $activity->causer?->getAttribute('email')),
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
        return $this->activities->list($this->filter($query));
    }

    public function rows(ReportQuery $query): LazyCollection
    {
        return $this->activities->reportRows($this->filter($query));
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

    private function label(string $group, ?string $value): ?string
    {
        return $value === null ? null : (string) (((array) __("reports.values.{$group}"))[$value] ?? $value);
    }

    /**
     * What changed, in words: "field: old → new" for a record's fields, else what an event recorded
     * ("report: cardholders; format: csv").
     */
    private function changes(Activity $activity): ?string
    {
        $fields = $activity->attribute_changes?->toArray() ?? [];
        if ($fields !== []) {
            $new = (array) ($fields['attributes'] ?? []);
            $old = (array) ($fields['old'] ?? []);
            $parts = array_map(fn (string $key) => array_key_exists($key, $old)
                ? "{$key}: {$this->text($old[$key])} → {$this->text($new[$key] ?? null)}"
                : "{$key}: {$this->text($new[$key] ?? null)}", array_keys($new + $old));
        } else {
            $parts = array_map(fn (string $key, mixed $value) => "{$key}: {$this->text($value)}", array_keys($properties = $activity->properties?->toArray() ?? []), $properties);
        }

        return $parts === [] ? null : implode('; ', $parts);
    }

    private function text(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '—',
            is_bool($value) => (string) __('reports.values.'.($value ? 'yes' : 'no')),
            is_array($value) => Str::limit((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200),
            default => Str::limit((string) $value, 200),
        };
    }
}
