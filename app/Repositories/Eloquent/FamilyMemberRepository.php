<?php

namespace App\Repositories\Eloquent;

use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\MasterDataItem;
use App\Repositories\Contracts\FamilyMemberRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<FamilyMember>
 */
class FamilyMemberRepository extends EloquentRepository implements FamilyMemberRepositoryInterface
{
    protected function model(): string
    {
        return FamilyMember::class;
    }

    public function listFor(Cardholder $cardholder): Collection
    {
        return $this->query()
            ->with('bloodGroup')
            ->where('cardholder_id', $cardholder->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function nextSortOrder(Cardholder $cardholder): int
    {
        return (int) $this->query()->where('cardholder_id', $cardholder->id)->max('sort_order') + 1;
    }

    public function usesMasterDataItem(MasterDataItem $item): bool
    {
        return $item->type === 'blood_group' && $this->query()->where('blood_group_id', $item->id)->exists();
    }
}
