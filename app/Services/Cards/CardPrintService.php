<?php

namespace App\Services\Cards;

use App\Data\Cards\PrintCardsData;
use App\Exceptions\BusinessRuleException;
use App\Models\Card;
use App\Models\CardPrint;
use App\Models\User;
use App\Repositories\Contracts\CardPrintRepositoryInterface;
use App\Repositories\Contracts\CardRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Printing issued cards (legacy card_print_history). Issuing and printing are separate: a card can be
 * printed again (e.g. after a printer jam) without a new number or a new card, and every printing is
 * logged with who printed it, how and how many copies. Only active, unexpired cards can be printed;
 * a lost or outdated card needs a new issue instead.
 */
class CardPrintService
{
    public function __construct(
        private CardRepositoryInterface $cards,
        private CardPrintRepositoryInterface $prints,
    ) {}

    /**
     * @return Collection<int, CardPrint>
     */
    public function history(Card $card): Collection
    {
        return $this->prints->listFor($card);
    }

    public function printCard(Card $card, PrintCardsData $data, User $actor): CardPrint
    {
        return $this->printCards([$card->public_id], $data, $actor)->firstOrFail();
    }

    /**
     * Log a printing of several cards at once (a batch); all of them or none are logged.
     *
     * @param  array<int, string>  $cardIds  public ids
     * @return Collection<int, CardPrint>
     */
    public function printCards(array $cardIds, PrintCardsData $data, User $actor): Collection
    {
        $cardIds = array_values(array_unique($cardIds));

        return DB::transaction(function () use ($cardIds, $data, $actor): Collection {
            $cards = $this->cards->lockedByPublicIds($cardIds);
            if ($cards->count() !== count($cardIds)) {
                throw ValidationException::withMessages(['cards' => __('cards.print.unknown')]);
            }
            $blocked = $cards->reject(fn (Card $card) => $this->printable($card));
            if ($blocked->isNotEmpty()) {
                throw new BusinessRuleException(__('cards.print.not_printable', ['numbers' => $blocked->pluck('number')->implode(', ')]));
            }

            $batch = $cards->count() > 1 ? Str::lower((string) Str::ulid()) : null;
            $now = now();
            $prints = $cards->map(fn (Card $card) => $this->prints->create([
                'card_id' => $card->id,
                'batch' => $batch,
                'method' => $data->method,
                'copies' => $data->copies,
                'note' => $data->note,
                'printed_by' => $actor->id,
                'printed_at' => $now,
            ])->setRelation('card', $card)->setRelation('printer', $actor));
            $this->cards->addPrints($cards->pluck('id')->all(), $data->copies, $now);

            return $prints;
        });
    }

    /**
     * Only the holder's current card, and only while it is valid, may be printed.
     */
    public function printable(Card $card): bool
    {
        return $card->isActive() && ! $card->isExpired();
    }
}
