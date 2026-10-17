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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
            ...self::wordsFor($channel, $words),
            ...$event->links($subject),
        ], $recipient, $sender));
    }

    /**
     * Log one message and hand it to the queue (or log it as skipped when the recipient has no address for the
     * channel). Also for messages that are not an event's, such as a test message.
     *
     * @param  array{event: string, channel: string, languages: string, subject: string|null, body: string, card_id?: int|null, appointment_id?: int|null, resend_of_id?: int|null}  $message
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
     * Send a failed or skipped message again, by hand: a new message (the old one stays, as history) with the
     * same words, to the address on the person's record now — a test message to the address it was typed to —,
     * whatever the organization's settings (like every message sent by hand). Once per message: sending it again
     * once more repeats the newer one. Noted in the audit log.
     *
     * @throws ValidationException when it cannot go (why, under `message`)
     */
    public function resend(NotificationMessage $message, User $sender): NotificationMessage
    {
        $resent = DB::transaction(function () use ($message, $sender): NotificationMessage {
            $message = $this->messages->lockForResend($message);

            if (! in_array($message->status, [NotificationStatus::Failed, NotificationStatus::Skipped], true)) {
                self::refuseResend('not_failed');
            }
            if ($message->resend !== null) {
                self::refuseResend('already');
            }
            if (! in_array($message->channel, NotificationSettings::channels(), true)) {
                self::refuseResend('unknown_channel');
            }
            $recipient = $this->recipientNow($message);
            if ($recipient === null) {
                self::refuseResend('no_recipient');
            }
            if ($recipient->addressFor($message->channel) === null) {
                self::refuseResend('no_address');
            }

            return $this->queue([
                'event' => $message->event,
                'channel' => $message->channel,
                'languages' => $message->languages,
                'subject' => $message->subject,
                'body' => $message->body,
                'card_id' => $message->card_id,
                'appointment_id' => $message->appointment_id,
                'resend_of_id' => $message->id,
            ], $recipient, $sender);
        });

        activity('notifications')
            ->performedOn($resent)
            ->causedBy($sender)
            ->event('resent')
            ->withProperties(['message' => $message->public_id, 'event' => $resent->event, 'channel' => $resent->channel, 'address' => $resent->address])
            ->log('resent');

        return $resent;
    }

    /**
     * A test message, by hand, to check that a channel gets through — before turning it on, too: fixed words
     * (lang/<language>/notifications.php → test) in the organization's languages and order, to the address typed,
     * logged as the sender's own message (event "test"), whatever the settings. Noted in the audit log.
     *
     * @param  string  $channel  a config('notifications.channels') key
     */
    public function sendTest(User $sender, string $channel, string $address): NotificationMessage
    {
        $settings = $this->tenant()->notificationSettings();
        $words = $this->composer->composeText(
            'notifications.test',
            ['organization.name' => (string) $this->tenant()->name],
            $settings['languages'],
            NotificationSettings::smsLanguagesOf($settings),
        );
        $byPhone = config("notifications.channels.{$channel}.address") === 'phone';

        $message = $this->queue([
            'event' => NotificationLogService::TEST_EVENT,
            'channel' => $channel,
            ...self::wordsFor($channel, $words),
        ], new NotificationRecipient($sender->name, $byPhone ? null : $address, $byPhone ? $address : null, userId: $sender->id), $sender);

        activity('notifications')
            ->performedOn($message)
            ->causedBy($sender)
            ->event('test_sent')
            ->withProperties(['channel' => $channel, 'address' => $message->address])
            ->log('test_sent');

        return $message;
    }

    /**
     * The words a channel carries and their languages: by e-mail the subject and the full text, by phone the short
     * text (no subject, in the SMS languages) — by the kind of address the channel goes to
     * (config('notifications.channels').<channel>.address).
     *
     * @param  array{languages: array<int, string>, sms_languages: array<int, string>, subject: string, body: string, sms: string}  $words
     * @return array{languages: string, subject: string|null, body: string}
     */
    private static function wordsFor(string $channel, array $words): array
    {
        $byEmail = config("notifications.channels.{$channel}.address") === 'email';

        return [
            'languages' => implode(',', $byEmail ? $words['languages'] : $words['sms_languages']),
            'subject' => $byEmail ? $words['subject'] : null,
            'body' => $byEmail ? $words['body'] : $words['sms'],
        ];
    }

    /**
     * @param  string  $why  key of lang/<language>/notifications.php → resend
     */
    private static function refuseResend(string $why): never
    {
        throw ValidationException::withMessages(['message' => __("notifications.resend.{$why}")]);
    }

    /**
     * Whom a logged message goes to now: the person's record as it is today, or for a test message the address
     * it was typed to; null when the record is gone.
     */
    private function recipientNow(NotificationMessage $message): ?NotificationRecipient
    {
        if ($message->event === NotificationLogService::TEST_EVENT && $message->user !== null) {
            $byPhone = config("notifications.channels.{$message->channel}.address") === 'phone';

            return new NotificationRecipient($message->user->name, $byPhone ? null : $message->address, $byPhone ? $message->address : null, userId: $message->user->id);
        }

        return match (true) {
            $message->cardholder !== null => NotificationRecipient::forCardholder($message->cardholder),
            $message->familyMember !== null => NotificationRecipient::forFamilyMember($message->familyMember),
            $message->user !== null => NotificationRecipient::forUser($message->user),
            default => null,
        };
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
