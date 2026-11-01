<?php

namespace App\Data\Webhooks;

use App\Enums\WebhookDeliveryStatus;

/**
 * Listing criteria for a webhook's deliveries, always newest first.
 */
final readonly class WebhookDeliveryFilter
{
    public function __construct(
        public ?WebhookDeliveryStatus $status = null,
        public ?string $event = null,
        public int $perPage = 15,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request input
     */
    public static function fromArray(array $input): self
    {
        $filter = $input['filter'] ?? [];

        return new self(
            status: WebhookDeliveryStatus::tryFrom((string) ($filter['status'] ?? '')),
            event: $filter['event'] ?? null,
            perPage: (int) ($input['per_page'] ?? 15),
        );
    }
}
