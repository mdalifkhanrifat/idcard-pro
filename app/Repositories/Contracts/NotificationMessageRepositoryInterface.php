<?php

namespace App\Repositories\Contracts;

use App\Models\NotificationMessage;
use Carbon\CarbonInterface;

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
}
