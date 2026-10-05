<?php

namespace App\Services\Cards;

use App\Enums\CardStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Card;
use App\Repositories\Contracts\CardRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Changes to an issued card after issue: its RF number, notes and expiry (legacy card form edit),
 * and revoking it. The printed design and text never change; a different card means a new issue.
 */
class CardService
{
    public function __construct(private CardRepositoryInterface $cards) {}

    /**
     * @param  array{rf_card_no?: string|null, notes?: string|null, expiry_date?: string|null}  $data
     */
    public function update(Card $card, array $data): Card
    {
        $changes = [];
        if (array_key_exists('rf_card_no', $data)) {
            $rf = trim((string) $data['rf_card_no']) ?: null;
            if ($rf !== null && $card->isActive() && $this->cards->rfCardInUse($rf, (string) $card->active_holder_key)) {
                throw ValidationException::withMessages(['rf_card_no' => __('cards.issue.rf_in_use')]);
            }
            $changes['rf_card_no'] = $rf;
        }
        if (array_key_exists('notes', $data)) {
            $changes['notes'] = trim((string) $data['notes']) ?: null;
        }
        if (array_key_exists('expiry_date', $data)) {
            $expiry = $data['expiry_date'] ? CarbonImmutable::parse($data['expiry_date']) : null;
            if ($expiry !== null && $expiry->lt($card->issue_date)) {
                throw ValidationException::withMessages(['expiry_date' => __('cards.issue.expiry_before_issue')]);
            }
            $changes['expiry_date'] = $expiry;
        }

        return $this->cards->update($card, $changes);
    }

    /**
     * Cancel an active card (e.g. reported lost, or the holder left). The holder can be issued a new one.
     */
    public function revoke(Card $card, string $note): Card
    {
        if (! $card->isActive()) {
            throw new BusinessRuleException(__('cards.lifecycle.not_active'));
        }

        return $this->cards->update($card, [
            'status' => CardStatus::Revoked,
            'status_changed_at' => now(),
            'status_note' => $note,
            'active_number_key' => null,
            'active_holder_key' => null,
        ]);
    }
}
