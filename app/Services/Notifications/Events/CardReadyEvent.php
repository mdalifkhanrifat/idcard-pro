<?php

namespace App\Services\Notifications\Events;

use App\Models\Card;
use App\Services\Notifications\NotificationEvent;

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
}
