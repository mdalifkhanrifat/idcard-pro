<?php

namespace App\Repositories\Contracts;

use App\Models\Card;
use App\Models\Cardholder;
use App\Models\CardTemplate;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<Card>
 */
interface CardRepositoryInterface extends RepositoryInterface
{
    /**
     * Every card of a cardholder and their family members, newest first.
     *
     * @return Collection<int, Card>
     */
    public function forCardholder(Cardholder $cardholder): Collection;

    /**
     * The holder's active card, locked for the issuing transaction.
     */
    public function activeForHolder(string $holderKey, bool $lock = false): ?Card;

    /**
     * The holder's latest card from a template with this code (for keeping the number on reissue).
     */
    public function latestForHolderWithCode(int $cardholderId, ?int $familyMemberId, string $code): ?Card;

    /**
     * Whether another holder's active card carries this RF card number.
     */
    public function rfCardInUse(string $rfCardNo, string $exceptHolderKey): bool;

    /**
     * Template image files that issued cards of this template still print (one snapshot per template version).
     *
     * @return array<int, string>
     */
    public function snapshotImagePaths(CardTemplate $template): array;
}
