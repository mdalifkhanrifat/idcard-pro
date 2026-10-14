<?php

namespace App\Services\Notifications\Events;

use App\Data\Notifications\NotificationRecipient;
use App\Models\Card;
use App\Services\Reports\ReportValueText;

/**
 * What every card event offers: placeholders for the organization, the holder (a family member on family
 * cards) and the card, as people read them in the current language; the message goes to the cardholder (also
 * for a family member's card: the cardholder is the one the organization is in touch with).
 */
trait DescribesCards
{
    /**
     * @return array<int, string>
     */
    protected function cardPlaceholders(): array
    {
        return ['organization.name', 'holder.name', 'holder.employee_no', 'card.number', 'card.template', 'card.issue_date', 'card.expiry_date', 'card.validity'];
    }

    protected function cardRecipient(Card $card): NotificationRecipient
    {
        $holder = $card->cardholder;

        return new NotificationRecipient($holder->full_name, $holder->email, $holder->personal_phone, cardholderId: $holder->id);
    }

    /**
     * @return array<string, string>
     */
    protected function cardValues(Card $card): array
    {
        $text = app(ReportValueText::class);
        $card->loadMissing(['cardholder.tenant', 'familyMember', 'template']);

        return [
            'organization.name' => (string) $card->cardholder->tenant->name,
            'holder.name' => (string) ($card->familyMember->name ?? $card->cardholder->full_name),
            'holder.employee_no' => $text->digits((string) $card->cardholder->employee_no),
            'card.number' => (string) $card->number,
            'card.template' => (string) $card->template->name,
            'card.issue_date' => $text->digits($card->issue_date->format('d/m/Y')),
            'card.expiry_date' => $card->expiry_date === null ? '' : $text->digits($card->expiry_date->format('d/m/Y')),
            // A whole sentence, right also for a card without an end date
            'card.validity' => $card->expiry_date === null
                ? (string) __('notifications.validity.none')
                : (string) __('notifications.validity.until', ['date' => $text->digits($card->expiry_date->format('d/m/Y'))]),
        ];
    }
}
