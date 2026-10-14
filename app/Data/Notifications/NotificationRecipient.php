<?php

namespace App\Data\Notifications;

/**
 * Whom a message goes to: the addresses on record and the record itself (at most one of the ids), kept with
 * every message of the log.
 */
final readonly class NotificationRecipient
{
    public function __construct(
        public string $name,
        public ?string $email = null,
        public ?string $phone = null,
        public ?int $cardholderId = null,
        public ?int $familyMemberId = null,
        public ?int $userId = null,
    ) {}

    /**
     * The address a channel uses (config('notifications.channels').<channel>.address: email or phone), or
     * null when there is none on record.
     */
    public function addressFor(string $channel): ?string
    {
        $address = match (config("notifications.channels.{$channel}.address")) {
            'email' => $this->email,
            'phone' => $this->phone,
            default => null,
        };

        return filled($address) ? trim((string) $address) : null;
    }

    /**
     * @return array{cardholder_id: int|null, family_member_id: int|null, user_id: int|null}
     */
    public function links(): array
    {
        return ['cardholder_id' => $this->cardholderId, 'family_member_id' => $this->familyMemberId, 'user_id' => $this->userId];
    }
}
