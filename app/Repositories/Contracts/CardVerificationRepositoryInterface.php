<?php

namespace App\Repositories\Contracts;

use App\Models\Card;
use App\Models\CardVerification;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<CardVerification>
 */
interface CardVerificationRepositoryInterface extends RepositoryInterface
{
    /**
     * The card's latest checks, newest first.
     *
     * @return Collection<int, CardVerification>
     */
    public function recentFor(Card $card, int $limit): Collection;

    /**
     * How many different devices checked the card (within the kept log).
     */
    public function devicesFor(Card $card): int;

    /**
     * Whether this device already checked the card since the given time (e.g. a page refresh).
     */
    public function checkedSince(Card $card, string $device, \DateTimeInterface $since): bool;
}
