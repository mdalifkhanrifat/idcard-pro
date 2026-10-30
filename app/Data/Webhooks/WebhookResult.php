<?php

namespace App\Data\Webhooks;

use App\Enums\WebhookFailure;

/**
 * What came of one attempt: delivered, or why not. The code is what the address answered, if it answered.
 */
final readonly class WebhookResult
{
    public function __construct(
        public ?WebhookFailure $failure = null,
        public ?int $code = null,
    ) {}

    public function delivered(): bool
    {
        return $this->failure === null;
    }
}
