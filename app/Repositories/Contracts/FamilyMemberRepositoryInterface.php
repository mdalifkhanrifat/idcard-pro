<?php

namespace App\Repositories\Contracts;

use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\MasterDataItem;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<FamilyMember>
 */
interface FamilyMemberRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<int, FamilyMember>
     */
    public function listFor(Cardholder $cardholder): Collection;

    public function nextSortOrder(Cardholder $cardholder): int;

    /**
     * Whether any family member references the master data item.
     */
    public function usesMasterDataItem(MasterDataItem $item): bool;
}
