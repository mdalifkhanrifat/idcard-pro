<?php

namespace App\Http\Resources;

use App\Models\NotificationMessage;
use App\Services\Notifications\NotificationLogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One message of the log, with its full address and text (notifications.view is the gate, by the owner's
 * decision). Keys come with their names in the reader's language, so new events and channels need no UI change.
 *
 * @mixin NotificationMessage
 */
class NotificationMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'event' => ['key' => $this->event, 'name' => NotificationLogService::eventName($this->event)],
            'channel' => ['key' => $this->channel, 'name' => NotificationLogService::label('channels', $this->channel)],
            'address' => $this->address,
            'recipient' => $this->recipient(),
            'card' => $this->card ? ['id' => $this->card->public_id, 'number' => $this->card->number] : null,
            'appointment' => $this->appointment ? ['id' => $this->appointment->public_id, 'number' => $this->appointment->number] : null,
            'languages' => explode(',', $this->languages),
            'subject' => $this->subject,
            'body' => $this->body,
            'status' => ['key' => $this->status->value, 'name' => NotificationLogService::label('statuses', $this->status->value)],
            'reason' => $this->reason === null ? null : ['key' => $this->reason, 'name' => NotificationLogService::label('reasons', $this->reason)],
            'error' => $this->error,
            'attempts' => $this->attempts,
            'driver' => $this->driver,
            'provider_id' => $this->provider_id,
            // Who sent it by hand; null when the system sent it on an event
            'sent_by' => $this->sender ? ['id' => $this->sender->public_id, 'name' => $this->sender->name] : null,
            // Sent again by hand: the message this one repeats, and the one that repeated this
            'resend_of' => $this->resendOf?->public_id,
            'resent_as' => $this->resend?->public_id,
            'created_at' => $this->created_at,
            'sent_at' => $this->sent_at,
            'failed_at' => $this->failed_at,
        ];
    }

    /**
     * Whom it went to, while the record is there (deleting it keeps the message, with its address).
     *
     * @return array{type: string, id: string, name: string, cardholder_id?: string}|null
     */
    private function recipient(): ?array
    {
        return match (true) {
            $this->cardholder !== null => ['type' => 'cardholder', 'id' => $this->cardholder->public_id, 'name' => $this->cardholder->full_name],
            // Its page lives under the cardholder's
            $this->familyMember !== null => ['type' => 'family_member', 'id' => $this->familyMember->public_id, 'name' => $this->familyMember->name, 'cardholder_id' => $this->familyMember->cardholder->public_id],
            $this->user !== null => ['type' => 'user', 'id' => $this->user->public_id, 'name' => $this->user->name],
            default => null,
        };
    }
}
