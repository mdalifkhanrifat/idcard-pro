<?php

namespace App\Enums;

/**
 * How a delivery went (6.2).
 */
enum WebhookDeliveryStatus: string
{
    // Waiting in the queue, or between two attempts
    case Queued = 'queued';
    // The address answered 2xx
    case Delivered = 'delivered';
    // Every attempt failed (see the failure)
    case Failed = 'failed';
}
