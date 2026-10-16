<?php

namespace App\Repositories\Contracts;

use App\Data\Notifications\NotificationMessageFilter;
use App\Models\NotificationMessage;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

/**
 * Messages sent or to be sent (5.2): the log of every message.
 *
 * @extends RepositoryInterface<NotificationMessage>
 */
interface NotificationMessageRepositoryInterface extends RepositoryInterface
{
    /**
     * Delete an organization's messages logged before a moment, in chunks (a large log never locks the table
     * for long); returns how many were deleted.
     */
    public function deleteOlderThan(int $tenantId, CarbonInterface $before, int $chunk = 1000): int;

    /**
     * A message by id in any organization (the queue sends outside a request), with its organization.
     */
    public function findForDelivery(int $id): ?NotificationMessage;

    /**
     * A page of the organization's messages, with whom and what each was about.
     *
     * @return LengthAwarePaginator<int, NotificationMessage>
     */
    public function list(NotificationMessageFilter $filter): LengthAwarePaginator;

    /**
     * Every message of the list's criteria, in its order, read a chunk at a time (the report's downloads).
     *
     * @return LazyCollection<int, NotificationMessage>
     */
    public function reportRows(NotificationMessageFilter $filter): LazyCollection;

    /**
     * Over every page of the list's criteria: messages, and how many were sent, failed or not sent.
     *
     * @return array{messages: int, sent: int, failed: int, skipped: int}
     */
    public function reportTotals(NotificationMessageFilter $filter): array;

    /**
     * A message locked until the transaction ends (two people sending it again at once make one resend), with
     * whom it went to and its resend, if any.
     */
    public function lockForResend(NotificationMessage $message): NotificationMessage;

    /**
     * One message with whom and what it was about, and who sent it.
     */
    public function withDetails(NotificationMessage $message): NotificationMessage;

    /**
     * Distinct values of a column in the organization's log (events or channels that occurred).
     *
     * @param  'event'|'channel'  $column
     * @return array<int, string>
     */
    public function loggedValues(string $column): array;
}
