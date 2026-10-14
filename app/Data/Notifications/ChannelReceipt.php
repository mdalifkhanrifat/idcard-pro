<?php

namespace App\Data\Notifications;

/**
 * What a channel says when it took a message: which driver sent it, and the provider's own id for it, if any.
 */
final readonly class ChannelReceipt
{
    public function __construct(
        public string $driver,
        public ?string $providerId = null,
    ) {}
}
