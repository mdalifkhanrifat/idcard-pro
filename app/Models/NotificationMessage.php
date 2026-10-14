<?php

namespace App\Models;

use App\Enums\NotificationStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\NotificationMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One message sent (or to be sent) on one channel (5.2). This table is itself the log of messages, so it is
 * not written to the activity log as well.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $event key of config('notifications.events'), or "test"
 * @property string $channel key of config('notifications.channels')
 * @property string|null $address e-mail address or phone number it went to
 * @property int|null $cardholder_id
 * @property int|null $family_member_id
 * @property int|null $user_id
 * @property int|null $card_id
 * @property string $languages e.g. "bn,en"
 * @property string|null $subject
 * @property string $body
 * @property NotificationStatus $status
 * @property string|null $reason why it was skipped
 * @property string|null $error what the channel answered when it failed
 * @property int $attempts
 * @property string|null $driver
 * @property string|null $provider_id
 * @property int|null $sent_by
 * @property int|null $resend_of_id the message this one sends again, by hand
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Cardholder|null $cardholder
 * @property-read FamilyMember|null $familyMember
 * @property-read User|null $user
 * @property-read Card|null $card
 * @property-read User|null $sender
 * @property-read NotificationMessage|null $resendOf
 * @property-read NotificationMessage|null $resend
 */
#[Fillable([
    'event', 'channel', 'address', 'cardholder_id', 'family_member_id', 'user_id', 'card_id', 'languages', 'subject',
    'body', 'status', 'reason', 'error', 'attempts', 'driver', 'provider_id', 'sent_by', 'resend_of_id', 'sent_at', 'failed_at',
])]
class NotificationMessage extends Model
{
    /** @use HasFactory<NotificationMessageFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => NotificationStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Cardholder, $this>
     */
    public function cardholder(): BelongsTo
    {
        return $this->belongsTo(Cardholder::class);
    }

    /**
     * @return BelongsTo<FamilyMember, $this>
     */
    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class);
    }

    /**
     * A user of the organization it went to (e.g. a test message).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Card, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /**
     * Who sent it by hand; null when the system sent it on an event.
     *
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * The message this one sends again (a failed or skipped one).
     *
     * @return BelongsTo<NotificationMessage, $this>
     */
    public function resendOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resend_of_id');
    }

    /**
     * The message that sent this one again, if any (at most one: the next resend repeats that one).
     *
     * @return HasOne<NotificationMessage, $this>
     */
    public function resend(): HasOne
    {
        return $this->hasOne(self::class, 'resend_of_id');
    }
}
