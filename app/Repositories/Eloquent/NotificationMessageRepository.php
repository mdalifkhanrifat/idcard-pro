<?php

namespace App\Repositories\Eloquent;

use App\Models\NotificationMessage;
use App\Repositories\Contracts\NotificationMessageRepositoryInterface;

/**
 * @extends EloquentRepository<NotificationMessage>
 */
class NotificationMessageRepository extends EloquentRepository implements NotificationMessageRepositoryInterface
{
    protected function model(): string
    {
        return NotificationMessage::class;
    }
}
