<?php

namespace App\Services\Notifications\Events;

use App\Data\Notifications\NotificationRecipient;
use App\Models\Card;
use App\Services\Notifications\NotificationEvent;
use App\Services\Reports\ReportValueText;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * A card's last day is near: time to renew it.
 *
 * @implements NotificationEvent<Card>
 */
class CardExpiringEvent implements NotificationEvent
{
    use DescribesCards;

    public function key(): string
    {
        return 'card_expiring';
    }

    public function placeholders(): array
    {
        return [...$this->cardPlaceholders(), 'card.days_left'];
    }

    public function defaultChannels(): array
    {
        return ['mail', 'sms'];
    }

    /**
     * @param  Card  $subject
     */
    public function values(object $subject): array
    {
        // Whole days from the organization's today to the card's last day, both as plain days in one zone
        $daysLeft = $subject->expiry_date === null ? null : (int) CarbonImmutable::parse(app(TenantContext::class)->today()->toDateString(), 'UTC')
            ->diffInDays(CarbonImmutable::parse($subject->expiry_date->toDateString(), 'UTC'), false);

        return [
            ...$this->cardValues($subject),
            'card.days_left' => $daysLeft === null ? '' : app(ReportValueText::class)->number($daysLeft),
        ];
    }

    /**
     * @param  Card  $subject
     */
    public function recipient(object $subject): NotificationRecipient
    {
        return $this->cardRecipient($subject);
    }

    /**
     * @param  Card  $subject
     */
    public function links(object $subject): array
    {
        return ['card_id' => $subject->id];
    }
}
