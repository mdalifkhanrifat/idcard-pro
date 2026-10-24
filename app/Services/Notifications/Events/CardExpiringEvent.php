<?php

namespace App\Services\Notifications\Events;

use App\Data\Notifications\EventOption;
use App\Data\Notifications\NotificationRecipient;
use App\Models\Card;
use App\Models\Tenant;
use App\Services\Notifications\HasEventOptions;
use App\Services\Notifications\NotificationEvent;
use App\Services\Reports\ReportValueText;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * A card's last day is near: time to renew it (5.7d, CardExpiryReminderService — once for each of the
 * organization's days before, the nearest when several are due).
 *
 * @implements NotificationEvent<Card>
 */
class CardExpiringEvent implements HasEventOptions, NotificationEvent
{
    use DescribesCards;

    /** Days before the card's last day a reminder goes: up to three, 1 to 365, e.g. [60, 30, 7] */
    public const DAYS = 'days';

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

    public function eventOptions(): array
    {
        return [EventOption::numbers(self::DAYS, 1, 365, 3, [30])];
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
     * A five-year card with 30 days left.
     */
    public function sample(Tenant $tenant): Card
    {
        $expires = app(TenantContext::class)->today()->addDays(30);

        return $this->sampleCard($tenant, $expires->subYears(5)->addDay(), $expires);
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
