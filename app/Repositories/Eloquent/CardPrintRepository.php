<?php

namespace App\Repositories\Eloquent;

use App\Models\Card;
use App\Models\CardPrint;
use App\Repositories\Contracts\CardPrintRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<CardPrint>
 */
class CardPrintRepository extends EloquentRepository implements CardPrintRepositoryInterface
{
    protected function model(): string
    {
        return CardPrint::class;
    }

    public function listFor(Card $card): Collection
    {
        return $this->query()
            ->with('printer')
            ->where('card_id', $card->id)
            ->latest('printed_at')
            ->latest('id')
            ->get();
    }
}
