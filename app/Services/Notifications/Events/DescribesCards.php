<?php

namespace App\Services\Notifications\Events;

use App\Data\Notifications\NotificationRecipient;
use App\Models\Card;
use App\Models\Cardholder;
use App\Models\CardTemplate;
use App\Models\Tenant;
use App\Services\Cards\CardNumberFormat;
use App\Services\Reports\ReportValueText;
use Carbon\CarbonInterface;

/**
 * What every card event offers: placeholders for the organization, the holder (a family member on family
 * cards) and the card, as people read them in the current language; the message goes to the cardholder (also
 * for a family member's card: the cardholder is the one the organization is in touch with).
 */
trait DescribesCards
{
    /** The sample card's template code and running number, e.g. EMP/26000123 */
    private const SAMPLE_CODE = 'EMP';

    private const SAMPLE_SEQUENCE = 123;

    /**
     * @return array<int, string>
     */
    protected function cardPlaceholders(): array
    {
        return ['organization.name', 'holder.name', 'holder.employee_no', 'card.number', 'card.template', 'card.issue_date', 'card.expiry_date', 'card.validity'];
    }

    protected function cardRecipient(Card $card): NotificationRecipient
    {
        return NotificationRecipient::forCardholder($card->loadMissing('cardholder')->cardholder);
    }

    /**
     * A card of an invented holder for previews, held in memory only: its number in the organization's format,
     * its holder and template named in the current language, every relation set so nothing is read from the
     * database.
     */
    protected function sampleCard(Tenant $tenant, CarbonInterface $issued, ?CarbonInterface $expires): Card
    {
        $holder = (new Cardholder)->forceFill([
            'full_name' => (string) __('notifications.samples.holder_name'),
            'employee_no' => (string) __('notifications.samples.employee_no'),
        ])->setRelation('tenant', $tenant);
        $template = (new CardTemplate)->forceFill(['code' => self::SAMPLE_CODE, 'name' => (string) __('notifications.samples.template')]);
        $format = new CardNumberFormat((string) ($tenant->settings['card_number_format'] ?? CardNumberFormat::DEFAULT));

        return (new Card)->forceFill([
            'number' => $format->render(self::SAMPLE_CODE, $issued, self::SAMPLE_SEQUENCE),
            'issue_date' => $issued->toDateString(),
            'expiry_date' => $expires?->toDateString(),
        ])->setRelation('cardholder', $holder)->setRelation('familyMember', null)->setRelation('template', $template);
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
