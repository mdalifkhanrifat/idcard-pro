<?php

namespace App\Services\Cards;

use App\Data\Cards\CardFilter;
use App\Data\Cards\PrintCardsData;
use App\Exceptions\BusinessRuleException;
use App\Models\Card;
use App\Models\CardPrint;
use App\Models\User;
use App\Repositories\Contracts\CardPrintRepositoryInterface;
use App\Repositories\Contracts\CardRepositoryInterface;
use DateTimeInterface;
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
        private CardRequirements $requirements,
        private CardNotifications $notifications,
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
     * Log a printing of several cards at once (a batch); all of them or none are logged. Cards printed for the
     * first time tell their holders they are ready (5.7d), once the printing is saved.
     *
     * @param  array<int, string>  $cardIds  public ids
     * @return Collection<int, CardPrint>
     */
    public function printCards(array $cardIds, PrintCardsData $data, User $actor): Collection
    {
        $cardIds = array_values(array_unique($cardIds));

        $firstPrinted = collect();
        $prints = DB::transaction(function () use ($cardIds, $data, $actor, &$firstPrinted): Collection {
            $cards = $this->assertPrintable($this->cards->lockedByPublicIds($cardIds), $cardIds);
            // Read under the lock, so of two printings at once only one is the first
            $firstPrinted = $cards->filter(fn (Card $card) => $card->print_count === 0)->values();
            // What the organization requires before printing, for every card of the batch (or an override with a reason)
            $overridden = $this->requirements->enforceCards($cards, 'print', $data->overrideReason, $actor);

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
            foreach ($cards->whereIn('public_id', array_keys($overridden)) as $card) {
                $this->requirements->recordOverride($card, 'print', (string) $data->overrideReason, $overridden[$card->public_id], $actor);
            }

            return $prints;
        });
        $this->notifications->firstPrinted($firstPrinted);

        return $prints;
    }

    /**
     * Log a printing that happened outside this system (e.g. the last printing in an imported file),
     * so the card counts as printed; one copy, by nobody here.
     */
    public function recordPast(Card $card, DateTimeInterface $printedAt, string $method): CardPrint
    {
        $print = $this->prints->create([
            'card_id' => $card->id,
            'method' => $method,
            'copies' => 1,
            'printed_by' => null,
            'printed_at' => $printedAt,
        ]);
        $this->cards->addPrints([$card->id], 1, $printedAt);

        return $print;
    }

    /**
     * The cards, in the order given, when every one exists and may be printed (e.g. before making a PDF).
     *
     * @param  array<int, string>  $cardIds  public ids
     * @return Collection<int, Card>
     *
     * @throws ValidationException An unknown card
     * @throws BusinessRuleException A card that may not be printed, named by number
     */
    public function printableCards(array $cardIds): Collection
    {
        $cardIds = array_values(array_unique($cardIds));

        return $this->assertPrintable($this->cards->byPublicIds($cardIds), $cardIds);
    }

    /**
     * @param  Collection<int, Card>  $cards
     * @param  array<int, string>  $cardIds
     * @return Collection<int, Card>
     */
    private function assertPrintable(Collection $cards, array $cardIds): Collection
    {
        if ($cards->count() !== count($cardIds)) {
            throw ValidationException::withMessages(['cards' => __('cards.print.unknown')]);
        }
        $blocked = $cards->reject(fn (Card $card) => $this->printable($card));
        if ($blocked->isNotEmpty()) {
            throw new BusinessRuleException(__('cards.print.not_printable', ['numbers' => $blocked->pluck('number')->implode(', ')]));
        }

        return $cards;
    }

    /**
     * Every card the list filter finds that may be printed, in the list's order, for printing them together
     * ("select all matching"); at most one batch, with how many there are in all.
     *
     * @return array{ids: array<int, string>, total: int, limit: int}
     */
    public function printableIds(CardFilter $filter): array
    {
        $limit = (int) config('cards.print.max_batch');

        return [...$this->cards->printablePublicIds($filter, $limit), 'limit' => $limit];
    }

    /**
     * Only the holder's current card, and only while it is valid, may be printed.
     */
    public function printable(Card $card): bool
    {
        return $card->isActive() && ! $card->isExpired();
    }
}
