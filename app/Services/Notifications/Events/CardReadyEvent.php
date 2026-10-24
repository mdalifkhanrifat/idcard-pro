<?php

namespace App\Services\Notifications\Events;

use App\Data\Notifications\EventOption;
use App\Data\Notifications\NotificationRecipient;
use App\Models\Card;
use App\Models\Tenant;
use App\Services\Notifications\HasEventOptions;
use App\Services\Notifications\NotificationEvent;
use App\Support\Tenancy\TenantContext;

/**
 * A card is ready to be collected (5.7d, CardNotifications): once, when it is first printed (a print the user
 * confirmed, or a server PDF recorded as printed) or, as the organization chooses, as soon as it is issued.
 * Printing it again sends nothing; an imported card is not "ready" by its import.
 *
 * @implements NotificationEvent<Card>
 */
class CardReadyEvent implements HasEventOptions, NotificationEvent
{
    use DescribesCards;

    /** When it goes: after the card's first printing (default — issuing is a record, the card exists once printed) or when it is issued */
    public const SEND_ON = 'send_on';

    public const ON_PRINT = 'print';

    public const ON_ISSUE = 'issue';

    public function key(): string
    {
        return 'card_ready';
    }

    public function placeholders(): array
    {
        return $this->cardPlaceholders();
    }

    public function defaultChannels(): array
    {
        return ['mail', 'sms'];
    }

    public function eventOptions(): array
    {
        return [EventOption::choice(self::SEND_ON, [self::ON_PRINT, self::ON_ISSUE], self::ON_PRINT)];
    }

    /**
     * @param  Card  $subject
     */
    public function values(object $subject): array
    {
        return $this->cardValues($subject);
    }

    /**
     * A card issued today, valid for five years.
     */
    public function sample(Tenant $tenant): Card
    {
        $today = app(TenantContext::class)->today();

        return $this->sampleCard($tenant, $today, $today->addYears(5)->subDay());
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
