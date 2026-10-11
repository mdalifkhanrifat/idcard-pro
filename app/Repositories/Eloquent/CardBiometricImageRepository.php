<?php

namespace App\Repositories\Eloquent;

use App\Models\Card;
use App\Models\CardBiometricImage;
use App\Repositories\Contracts\CardBiometricImageRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<CardBiometricImage>
 */
class CardBiometricImageRepository extends EloquentRepository implements CardBiometricImageRepositoryInterface
{
    protected function model(): string
    {
        return CardBiometricImage::class;
    }

    public function forCard(Card $card): Collection
    {
        return $this->query()->where('card_id', $card->id)->get()->keyBy('field');
    }

    public function findAnyTenant(string $publicId): ?CardBiometricImage
    {
        return CardBiometricImage::query()->withoutGlobalScopes()->where('public_id', $publicId)->first();
    }
}
