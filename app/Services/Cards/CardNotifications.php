<?php

namespace App\Services\Cards;

use App\Models\Card;
use App\Services\Notifications\Events\CardReadyEvent;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\NotificationSettings;
use App\Support\Tenancy\TenantContext;
use Throwable;

/**
 * Which message a card's change sends its holder (5.7d) — one place, called once the change is saved: "card
 * ready" once per card, when it is first printed or, as the organization chose (the event's own setting
 * `send_on`), when it is issued. Printing a card again sends nothing (it was ready already), and neither does
 * importing one. And reminders before a card's last day (CardExpiryReminderService). Each goes as the organization's notification settings say (off: nothing); a message that
 * cannot be logged never undoes the issue or the print: it is reported and the change stands.
 */
class CardNotifications
{
    public function __construct(
        private NotificationService $notifications,
        private TenantContext $tenant,
    ) {}

    public function issued(Card $card): void
    {
        if ($this->readyOn() === CardReadyEvent::ON_ISSUE) {
            $this->send('card_ready', $card);
        }
    }

    /**
     * After a printing that was the first for these cards.
     *
     * @param  iterable<int, Card>  $cards
     */
    public function firstPrinted(iterable $cards): void
    {
        if ($this->readyOn() !== CardReadyEvent::ON_PRINT) {
            return;
        }
        foreach ($cards as $card) {
            $this->send('card_ready', $card);
        }
    }

    /**
     * A reminder before the card's last day (CardExpiryReminderService).
     */
    public function expiring(Card $card): void
    {
        $this->send('card_expiring', $card);
    }

    private function readyOn(): string
    {
        return (string) NotificationSettings::option($this->tenant->get()->notificationSettings(), 'card_ready', CardReadyEvent::SEND_ON);
    }

    private function send(string $event, Card $card): void
    {
        try {
            $this->notifications->notify($event, $card);
        } catch (Throwable $failure) {
            report($failure);
        }
    }
}
