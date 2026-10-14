<?php

namespace App\Data\Notifications;

use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\User;

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
     * A cardholder, at the addresses on their record now (personal e-mail and phone).
     */
    public static function forCardholder(Cardholder $cardholder): self
    {
        return new self($cardholder->full_name, $cardholder->email, $cardholder->personal_phone, cardholderId: $cardholder->id);
    }

    /**
     * A family member: a phone only, as their record keeps no e-mail address.
     */
    public static function forFamilyMember(FamilyMember $member): self
    {
        return new self($member->name, null, $member->phone, familyMemberId: $member->id);
    }

    /**
     * A user of the organization.
     */
    public static function forUser(User $user): self
    {
        return new self($user->name, $user->email, $user->phone, userId: $user->id);
    }

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
