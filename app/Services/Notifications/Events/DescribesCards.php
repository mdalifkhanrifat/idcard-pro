<?php

namespace App\Services\Notifications\Events;

use App\Models\Card;
use App\Services\Reports\ReportValueText;

/**
 * Placeholders every card event offers: the organization, the holder (a family member on family cards) and
 * the card, as people read them in the current language.
 */
trait DescribesCards
{
    /**
     * @return array<int, string>
     */
    protected function cardPlaceholders(): array
    {
        return ['organization.name', 'holder.name', 'holder.employee_no', 'card.number', 'card.template', 'card.issue_date', 'card.expiry_date'];
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
        ];
    }
}
