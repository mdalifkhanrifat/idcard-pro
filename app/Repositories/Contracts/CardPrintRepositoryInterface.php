<?php

namespace App\Repositories\Contracts;

use App\Models\Card;
use App\Models\CardPrint;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<CardPrint>
 */
interface CardPrintRepositoryInterface extends RepositoryInterface
{
    /**
     * Printings of a card, newest first, with who printed it.
     *
     * @return Collection<int, CardPrint>
     */
    public function listFor(Card $card): Collection;
}
