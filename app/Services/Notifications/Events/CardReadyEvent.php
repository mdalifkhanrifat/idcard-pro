<?php

namespace App\Services\Notifications\Events;

use App\Data\Notifications\NotificationRecipient;
use App\Models\Card;
use App\Models\Tenant;
use App\Services\Notifications\NotificationEvent;
use App\Support\Tenancy\TenantContext;

/**
 * A card was issued and can be collected.
 *
 * @implements NotificationEvent<Card>
 */
class CardReadyEvent implements NotificationEvent
{
    use DescribesCards;

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
