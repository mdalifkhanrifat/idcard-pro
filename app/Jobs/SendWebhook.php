<?php

namespace App\Jobs;

use App\Services\Webhooks\WebhookDeliveryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells an address one thing (6.2). On a queue of its own, so that an address that does not answer holds up webhooks
 * and not the organization's messages. Tried again after each wait of its schedule, then marked failed.
 *
 * The schedule is the organization's (WebhookSettings::backoff), given when the delivery is queued: the queue asks
 * for tries and waits before anything here knows the organization, and a job has none of its own.
 */
class SendWebhook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    /**
     * Seconds to wait before each try after the first; null for a job queued before organizations chose (the
     * installation's whole schedule).
     *
     * @var array<int, int>|null
     */
    public ?array $schedule = null;

    /**
     * @param  array<int, int>|null  $backoff
     */
    public function __construct(public int $deliveryId, ?array $backoff = null)
    {
        $this->schedule = $backoff === null ? null : array_values(array_map(intval(...), $backoff));
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
        return $this->schedule ?? array_map(intval(...), (array) config('webhooks.retry.backoff'));
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
