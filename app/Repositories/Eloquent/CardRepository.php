<?php

namespace App\Repositories\Eloquent;

use App\Models\Card;
use App\Models\Cardholder;
use App\Repositories\Contracts\CardRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<Card>
 */
class CardRepository extends EloquentRepository implements CardRepositoryInterface
{
    protected function model(): string
    {
        return Card::class;
    }

    public function forCardholder(Cardholder $cardholder): Collection
    {
        return $this->query()
            ->where('cardholder_id', $cardholder->id)
            ->with(['template:id,public_id,name,code', 'familyMember:id,public_id,name,relation', 'issuer:id,name', 'replacedBy:id,public_id,number', 'document'])
            ->latest('id')
            ->get();
    }

    public function activeForHolder(string $holderKey, bool $lock = false): ?Card
    {
        return $this->query()->where('active_holder_key', $holderKey)->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    public function latestForHolderWithCode(int $cardholderId, ?int $familyMemberId, string $code): ?Card
    {
        return $this->query()
            ->select('cards.*')
            ->join('card_templates', 'card_templates.id', '=', 'cards.card_template_id')
            ->where('cards.cardholder_id', $cardholderId)
            ->where('cards.family_member_id', $familyMemberId)
            ->where('card_templates.code', $code)
            ->latest('cards.id')
            ->first();
    }

    public function rfCardInUse(string $rfCardNo, string $exceptHolderKey): bool
    {
        return $this->query()
            ->where('rf_card_no', $rfCardNo)
            ->whereNotNull('active_holder_key')
            ->where('active_holder_key', '!=', $exceptHolderKey)
            ->exists();
    }
}
