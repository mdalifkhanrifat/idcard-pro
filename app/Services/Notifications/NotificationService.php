<?php

namespace App\Services\Notifications;

use App\Data\Notifications\NotificationRecipient;
use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationMessage;
use App\Models\NotificationMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Contracts\NotificationMessageRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Telling people what happened, as the organization chose (settings.notifications): nothing while notifications
 * or the event are off; otherwise one message per channel that both the organization and the event use, in the
 * organization's languages and words, logged and handed to the queue. A channel without an address on record
 * is logged as skipped, with the reason.
 */
class NotificationService
{
    public const NO_ADDRESS = 'no_address';

    public function __construct(
        private NotificationMessageRepositoryInterface $messages,
        private NotificationComposer $composer,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @param  string  $eventKey  config('notifications.events') key
     * @param  object  $subject  what happened to (e.g. a card)
     * @param  User|null  $sender  who sent it by hand; null when the system sends it on the event
     * @return Collection<int, NotificationMessage> the messages logged (none while off)
     */
    public function notify(string $eventKey, object $subject, ?User $sender = null): Collection
    {
        $event = NotificationSettings::events()[$eventKey] ?? throw new InvalidArgumentException("Unknown notification event \"{$eventKey}\".");
        $settings = $this->tenant()->notificationSettings();
        $channels = self::channelsFor($settings, $eventKey);
        if ($channels === []) {
            return collect();
        }

        $words = $this->composer->compose($event, $subject, $settings);
        $recipient = $event->recipient($subject);

        return collect($channels)->map(fn (string $channel) => $this->queue([
            'event' => $eventKey,
            'channel' => $channel,
            'languages' => implode(',', $words['languages']),
            'subject' => $channel === 'mail' ? $words['subject'] : null,
            'body' => $channel === 'mail' ? $words['body'] : $words['sms'],
            ...$event->links($subject),
        ], $recipient, $sender));
    }

    /**
     * Log one message and hand it to the queue (or log it as skipped when the recipient has no address for the
     * channel). Also for messages that are not an event's, such as a test message.
     *
     * @param  array{event: string, channel: string, languages: string, subject: string|null, body: string}  $message
     */
    public function queue(array $message, NotificationRecipient $recipient, ?User $sender = null): NotificationMessage
    {
        $address = $recipient->addressFor($message['channel']);
        $logged = $this->messages->create([
            ...$message,
            ...$recipient->links(),
            'address' => $address,
            'status' => $address === null ? NotificationStatus::Skipped : NotificationStatus::Queued,
            'reason' => $address === null ? self::NO_ADDRESS : null,
            'sent_by' => $sender?->id,
        ]);
        if ($address !== null) {
            // Only once whatever caused it is saved
            SendNotificationMessage::dispatch($logged->id)->afterCommit();
        }

        return $logged;
    }

    /**
     * Channels an event goes on now: the organization's (in its order) that the event uses, none while
     * notifications or the event are off.
     *
     * @param  array{enabled: bool, channels: array<int, string>, events: array<string, array{enabled: bool, channels: array<int, string>}>}  $settings  normalized
     * @return array<int, string>
     */
    public static function channelsFor(array $settings, string $eventKey): array
    {
        $event = $settings['events'][$eventKey] ?? null;
        if (! $settings['enabled'] || $event === null || ! $event['enabled']) {
            return [];
        }

        return array_values(array_intersect($settings['channels'], $event['channels']));
    }

    private function tenant(): Tenant
    {
        return $this->tenantContext->get() ?? throw new InvalidArgumentException('Notifications are sent inside an organization.');
    }
}
