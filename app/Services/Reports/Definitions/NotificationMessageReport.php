<?php

namespace App\Services\Reports\Definitions;

use App\Data\Notifications\NotificationMessageFilter;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Repositories\Contracts\NotificationMessageRepositoryInterface;
use App\Services\Notifications\NotificationLogService;
use App\Services\Reports\Concerns\DescribesColumns;
use App\Services\Reports\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * Sent messages (5.2) with the log's own criteria: to whom, on which channel, about what, how it went and why.
 * The full address and text, as the log shows them to those who may see it (the owner's decision).
 *
 * @implements Report<NotificationMessage>
 */
class NotificationMessageReport implements Report
{
    use DescribesColumns;

    public function __construct(
        private NotificationMessageRepositoryInterface $messages,
        private NotificationLogService $log,
    ) {}

    public function key(): string
    {
        return 'notification_messages';
    }

    public function allows(User $user): bool
    {
        return $user->can('notifications.view');
    }

    public function columns(): array
    {
        $timezone = $this->timezone();
        $column = fn (string $key, string $type, string $group, \Closure $value) => [$key => $this->column($key, $type, $group, $value)];

        return [
            ...$column('message.created_at', ReportColumn::DATETIME, 'message', fn (NotificationMessage $message) => $message->created_at?->setTimezone($timezone)),
            ...$column('message.event', ReportColumn::TEXT, 'message', fn (NotificationMessage $message) => NotificationLogService::eventName($message->event)),
            ...$column('message.channel', ReportColumn::TEXT, 'message', fn (NotificationMessage $message) => NotificationLogService::label('channels', $message->channel)),
            ...$column('message.address', ReportColumn::TEXT, 'message', fn (NotificationMessage $message) => $message->address),
            ...$column('message.recipient', ReportColumn::TEXT, 'recipient', fn (NotificationMessage $message) => $this->recipientName($message)),
            ...$column('message.recipient_type', ReportColumn::TEXT, 'recipient', fn (NotificationMessage $message) => ($type = $this->recipientType($message)) ? (string) __("reports.values.recipient_types.{$type}") : null),
            ...$column('message.employee_no', ReportColumn::TEXT, 'recipient', fn (NotificationMessage $message) => $message->cardholder->employee_no ?? $message->familyMember?->cardholder?->employee_no),
            ...$column('message.card', ReportColumn::TEXT, 'message', fn (NotificationMessage $message) => $message->card?->number),
            ...$column('message.appointment', ReportColumn::TEXT, 'message', fn (NotificationMessage $message) => $message->appointment?->number),
            ...$column('message.languages', ReportColumn::TEXT, 'message', fn (NotificationMessage $message) => implode(', ', array_map(fn (string $code) => NotificationLogService::label('languages', $code), explode(',', $message->languages)))),
            ...$column('message.status', ReportColumn::TEXT, 'message', fn (NotificationMessage $message) => NotificationLogService::label('statuses', $message->status->value)),
            ...$column('message.reason', ReportColumn::TEXT, 'message', fn (NotificationMessage $message) => $message->reason === null ? null : NotificationLogService::label('reasons', $message->reason)),
            ...$column('message.error', ReportColumn::TEXT, 'message', fn (NotificationMessage $message) => $message->error),
            ...$column('message.attempts', ReportColumn::NUMBER, 'message', fn (NotificationMessage $message) => $message->attempts),
            ...$column('message.driver', ReportColumn::TEXT, 'message', fn (NotificationMessage $message) => $message->driver),
            ...$column('message.provider_id', ReportColumn::TEXT, 'message', fn (NotificationMessage $message) => $message->provider_id),
            ...$column('message.sent_by', ReportColumn::TEXT, 'message', fn (NotificationMessage $message) => $message->sender->name ?? (string) __('reports.values.system')),
            ...$column('message.sent_again', ReportColumn::TEXT, 'message', fn (NotificationMessage $message) => (string) __('reports.values.'.($message->resend_of_id !== null ? 'yes' : 'no'))),
            ...$column('message.sent_at', ReportColumn::DATETIME, 'message', fn (NotificationMessage $message) => $message->sent_at?->setTimezone($timezone)),
            ...$column('message.failed_at', ReportColumn::DATETIME, 'message', fn (NotificationMessage $message) => $message->failed_at?->setTimezone($timezone)),
            ...$column('message.subject', ReportColumn::TEXT, 'message', fn (NotificationMessage $message) => $message->subject),
            ...$column('message.body', ReportColumn::TEXT, 'message', fn (NotificationMessage $message) => $message->body),
        ];
    }

    public function defaultColumns(): array
    {
        return ['message.created_at', 'message.event', 'message.channel', 'message.recipient', 'message.address', 'message.card', 'message.status', 'message.reason'];
    }

    public function filters(): array
    {
        $options = $this->log->options();
        $choices = fn (array $items) => array_map(fn (array $item) => ['value' => $item['key'], 'label' => $item['name']], $items);

        return [
            new ReportFilterField('search', ReportFilterField::SEARCH, (string) __('reports.filters.search_messages')),
            new ReportFilterField('status', ReportFilterField::SELECT, $this->columnLabel('message.status'), $choices($options['statuses'])),
            new ReportFilterField('channel', ReportFilterField::SELECT, $this->columnLabel('message.channel'), $choices($options['channels'])),
            new ReportFilterField('event', ReportFilterField::SELECT, $this->columnLabel('message.event'), $choices($options['events'])),
            // One cardholder's messages (theirs and their family members'), from their page
            new ReportFilterField('cardholder', ReportFilterField::CARDHOLDER, (string) __('reports.filters.cardholder')),
            new ReportFilterField('date_from', ReportFilterField::DATE, (string) __('reports.filters.on_or_after')),
            new ReportFilterField('date_to', ReportFilterField::DATE, (string) __('reports.filters.on_or_before'), after: 'date_from'),
        ];
    }

    public function sorts(): array
    {
        return ['message.created_at' => 'created_at', 'message.sent_at' => 'sent_at'];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function totalKeys(): array
    {
        return ['messages', 'sent', 'failed', 'skipped'];
    }

    public function paginate(ReportQuery $query): LengthAwarePaginator
    {
        return $this->messages->list($this->filter($query));
    }

    public function rows(ReportQuery $query): LazyCollection
    {
        return $this->messages->reportRows($this->filter($query));
    }

    public function count(ReportQuery $query): int
    {
        return $this->totals($query)['messages'];
    }

    public function totals(ReportQuery $query): array
    {
        return $this->messages->reportTotals($this->filter($query));
    }

    /**
     * @param  NotificationMessage  $row
     */
    public function present(Model $row): array
    {
        return ['id' => $row->public_id, 'event' => $row->event, 'channel' => $row->channel, 'status' => $row->status->value];
    }

    /**
     * The log's own reading of the criteria, so a download holds the list's rows.
     */
    private function filter(ReportQuery $query): NotificationMessageFilter
    {
        return NotificationMessageFilter::fromArray($query->input());
    }

    /**
     * Whom it went to while the record is there (deleting it keeps the message and its address).
     */
    private function recipientName(NotificationMessage $message): ?string
    {
        return $message->cardholder->full_name ?? $message->familyMember->name ?? $message->user?->name;
    }

    /**
     * @return 'cardholder'|'family_member'|'user'|null
     */
    private function recipientType(NotificationMessage $message): ?string
    {
        return match (true) {
            $message->cardholder !== null => 'cardholder',
            $message->familyMember !== null => 'family_member',
            $message->user !== null => 'user',
            default => null,
        };
    }
}
