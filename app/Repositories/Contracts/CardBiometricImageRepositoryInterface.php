<?php

namespace App\Repositories\Contracts;

use App\Models\Card;
use App\Models\CardBiometricImage;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<CardBiometricImage>
 */
interface CardBiometricImageRepositoryInterface extends RepositoryInterface
{
    /**
     * Images copied for a card at issue, keyed by the design element's field.
     *
     * @return Collection<string, CardBiometricImage>
     */
    public function forCard(Card $card): Collection;

    /**
     * Find a copy regardless of the current tenant (used by signed image links).
     */
    public function findAnyTenant(string $publicId): ?CardBiometricImage;
}
