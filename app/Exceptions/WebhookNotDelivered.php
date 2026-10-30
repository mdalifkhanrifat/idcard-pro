<?php

namespace App\Exceptions;

use App\Enums\WebhookFailure;
use RuntimeException;

/**
 * An attempt failed, so the queue tries again. It says only which delivery and why from the short list; an address
 * being down is ordinary, and goes to the delivery log rather than the application's.
 */
class WebhookNotDelivered extends RuntimeException
{
    public function __construct(string $deliveryId, WebhookFailure $failure)
    {
        parent::__construct("Webhook delivery {$deliveryId}: {$failure->value}");
    }

    public function report(): void {}
}
