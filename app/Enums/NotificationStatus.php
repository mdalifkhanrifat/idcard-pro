<?php

namespace App\Enums;

/**
 * How a message went (5.2).
 */
enum NotificationStatus: string
{
    // Waiting in the queue to go out
    case Queued = 'queued';
    // Handed to the channel's service
    case Sent = 'sent';
    // Every attempt failed (see the error)
    case Failed = 'failed';
    // Not sent, for a reason (e.g. no e-mail address on record)
    case Skipped = 'skipped';
}
