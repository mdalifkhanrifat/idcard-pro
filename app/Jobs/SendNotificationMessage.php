<?php

namespace App\Jobs;

use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one logged message (5.2). Tried again after config('notifications.retry.backoff') seconds, then marked
 * failed; one job per message at a time, and a message already sent is never sent again.
 */
class SendNotificationMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(public int $messageId) {}

    public function uniqueId(): string
    {
        return (string) $this->messageId;
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
        return array_map(intval(...), (array) config('notifications.retry.backoff'));
    }

    public function handle(NotificationDeliveryService $delivery): void
    {
        $delivery->deliver($this->messageId);
    }

    public function failed(?Throwable $exception): void
    {
        app(NotificationDeliveryService::class)->fail($this->messageId, $exception);
    }
}
