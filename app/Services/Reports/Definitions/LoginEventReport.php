<?php

namespace App\Services\Reports\Definitions;

use App\Data\Audit\LoginEventFilter;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Enums\LoginEventType;
use App\Models\LoginEvent;
use App\Models\User;
use App\Repositories\Contracts\LoginEventRepositoryInterface;
use App\Services\Reports\Concerns\DescribesColumns;
use App\Services\Reports\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * Sign-in events of the audit log with the audit page's criteria: who tried, what happened, from where.
 *
 * @implements Report<LoginEvent>
 */
class LoginEventReport implements Report
{
    use DescribesColumns;

    public function __construct(private LoginEventRepositoryInterface $events) {}

    public function key(): string
    {
        return 'audit_logins';
    }

    public function allows(User $user): bool
    {
        return $user->can('audit.view');
    }

    public function columns(): array
    {
        $timezone = $this->timezone();
        $column = fn (string $key, string $type, \Closure $value) => [$key => $this->column($key, $type, 'login', $value)];

        return [
            ...$column('login.when', ReportColumn::DATETIME, fn (LoginEvent $event) => $event->created_at?->setTimezone($timezone)),
            ...$column('login.event', ReportColumn::TEXT, fn (LoginEvent $event) => (string) (((array) __('reports.values.audit_events'))[$event->event->value] ?? $event->event->value)),
            ...$column('login.identifier', ReportColumn::TEXT, fn (LoginEvent $event) => $event->identifier),
            ...$column('login.user', ReportColumn::TEXT, fn (LoginEvent $event) => $event->user?->name),
            ...$column('login.user_email', ReportColumn::TEXT, fn (LoginEvent $event) => $event->user?->email),
            ...$column('login.ip_address', ReportColumn::TEXT, fn (LoginEvent $event) => $event->ip_address),
            ...$column('login.user_agent', ReportColumn::TEXT, fn (LoginEvent $event) => $event->user_agent),
        ];
    }

    public function defaultColumns(): array
    {
        return ['login.when', 'login.event', 'login.identifier', 'login.user', 'login.ip_address'];
    }

    public function filters(): array
    {
        $events = (array) __('reports.values.audit_events');

        return [
            new ReportFilterField('event', ReportFilterField::SELECT, $this->columnLabel('login.event'), array_map(
                fn (LoginEventType $type) => ['value' => $type->value, 'label' => (string) ($events[$type->value] ?? $type->value)],
                LoginEventType::cases(),
            )),
            new ReportFilterField('identifier', ReportFilterField::SEARCH, $this->columnLabel('login.identifier')),
            new ReportFilterField('ip_address', ReportFilterField::SEARCH, $this->columnLabel('login.ip_address')),
            new ReportFilterField('date_from', ReportFilterField::DATE, (string) __('reports.filters.on_or_after')),
            new ReportFilterField('date_to', ReportFilterField::DATE, (string) __('reports.filters.on_or_before'), after: 'date_from'),
        ];
    }

    public function sorts(): array
    {
        return ['login.when' => 'id'];
    }

    public function defaultSort(): string
    {
        return '-id';
    }

    public function totalKeys(): array
    {
        return ['events', 'succeeded', 'addresses'];
    }

    public function paginate(ReportQuery $query): LengthAwarePaginator
    {
        return $this->events->list($this->filter($query));
    }

    public function rows(ReportQuery $query): LazyCollection
    {
        return $this->events->reportRows($this->filter($query));
    }

    public function count(ReportQuery $query): int
    {
        return $this->totals($query)['events'];
    }

    public function totals(ReportQuery $query): array
    {
        return $this->events->reportTotals($this->filter($query));
    }

    /**
     * @param  LoginEvent  $row
     */
    public function present(Model $row): array
    {
        return ['id' => $row->getKey(), 'event' => $row->event->value];
    }

    private function filter(ReportQuery $query): LoginEventFilter
    {
        return LoginEventFilter::fromArray($query->input());
    }
}
