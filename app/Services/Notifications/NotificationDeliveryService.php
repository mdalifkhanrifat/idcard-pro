<?php

namespace App\Services\Notifications;

use App\Enums\NotificationStatus;
use App\Models\NotificationMessage;
use App\Repositories\Contracts\NotificationMessageRepositoryInterface;
use App\Services\Notifications\Channels\NotificationChannel;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Handing a logged message to its channel (from the queue). A message goes out once: only while it is still
 * queued. An event's message whose organization meanwhile turned that event (or channel, or notifications) off
 * is skipped; a test or hand-sent message goes as asked.
 */
class NotificationDeliveryService
{
    public const TURNED_OFF = 'turned_off';

    public function __construct(
        private NotificationMessageRepositoryInterface $messages,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @throws Throwable when the channel did not take it (the error is kept; the queue tries again)
     */
    public function deliver(int $messageId): void
    {
        $message = $this->messages->findForDelivery($messageId);
        if ($message === null || $message->status !== NotificationStatus::Queued) {
            return;
        }

        $this->tenantContext->run($message->tenant, function () use ($message): void {
            if ($message->sent_by === null && ! $this->stillWanted($message)) {
                $this->messages->update($message, ['status' => NotificationStatus::Skipped, 'reason' => self::TURNED_OFF]);

                return;
            }

            $this->messages->update($message, ['attempts' => $message->attempts + 1]);
            try {
                $receipt = $this->channel($message->channel)->send($message);
            } catch (Throwable $exception) {
                $this->messages->update($message, ['error' => Str::limit($exception->getMessage(), 2000)]);

                throw $exception;
            }
            $this->messages->update($message, [
                'status' => NotificationStatus::Sent,
                'driver' => $receipt->driver,
                'provider_id' => $receipt->providerId,
                'error' => null,
                'sent_at' => now(),
            ]);
        });
    }

    /**
     * Every attempt failed: the message stays in the log as failed, with the last error.
     */
    public function fail(int $messageId, ?Throwable $exception): void
    {
        $message = $this->messages->findForDelivery($messageId);
        if ($message === null || $message->status !== NotificationStatus::Queued) {
            return;
        }
        $this->messages->update($message, [
            'status' => NotificationStatus::Failed,
            'failed_at' => now(),
            'error' => $message->error ?? ($exception === null ? null : Str::limit($exception->getMessage(), 2000)),
        ]);
    }

    /**
     * An event's message is still wanted while the organization sends that event on that channel.
     */
    private function stillWanted(NotificationMessage $message): bool
    {
        if (! array_key_exists($message->event, NotificationSettings::events())) {
            return true;
        }

        return in_array($message->channel, NotificationService::channelsFor($message->tenant->notificationSettings(), $message->event), true);
    }

    private function channel(string $channel): NotificationChannel
    {
        $class = config("notifications.channels.{$channel}.class");
        if (! is_string($class)) {
            throw new RuntimeException("No notification channel called \"{$channel}\" is set up.");
        }
        $instance = app($class);
        assert($instance instanceof NotificationChannel);

        return $instance;
    }
}
