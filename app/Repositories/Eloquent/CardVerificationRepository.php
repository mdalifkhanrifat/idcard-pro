<?php

namespace App\Repositories\Eloquent;

use App\Models\Card;
use App\Models\CardVerification;
use App\Repositories\Contracts\CardVerificationRepositoryInterface;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<CardVerification>
 */
class CardVerificationRepository extends EloquentRepository implements CardVerificationRepositoryInterface
{
    protected function model(): string
    {
        return CardVerification::class;
    }

    public function recentFor(Card $card, int $limit): Collection
    {
        return $this->query()->where('card_id', $card->id)->latest('verified_at')->latest('id')->limit($limit)->get();
    }

    public function devicesFor(Card $card): int
    {
        return $this->query()->where('card_id', $card->id)->distinct()->count('device');
    }

    public function checkedSince(Card $card, string $device, DateTimeInterface $since): bool
    {
        return $this->query()->where('card_id', $card->id)->where('device', $device)->where('verified_at', '>=', $since)->exists();
    }
}
