<?php

namespace App\Jobs;

use App\Services\Webhooks\WebhookDeliveryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells an address one thing (6.2). On a queue of its own, so that an address that does not answer holds up webhooks
 * and not the organization's messages. Tried again after config('webhooks.retry.backoff') seconds, then marked failed.
 */
class SendWebhook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue((string) config('webhooks.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    /** As long as the last try may still come. */
    public function uniqueFor(): int
    {
        return (int) array_sum($this->backoff()) + 3600;
    }

    public function tries(): int
    {
        return count($this->backoff()) + 1;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_map(intval(...), (array) config('webhooks.retry.backoff'));
    }

    public function handle(WebhookDeliveryService $deliveries): void
    {
        $deliveries->deliver($this->deliveryId);
    }

    public function failed(): void
    {
        app(WebhookDeliveryService::class)->fail($this->deliveryId);
    }
}
