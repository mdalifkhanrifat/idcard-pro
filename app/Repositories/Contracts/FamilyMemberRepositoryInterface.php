<?php

namespace App\Repositories\Contracts;

use App\Enums\BiometricStatus;
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

    /**
     * Keep the biometric status of family members; not an edit of the person (no audit entry, no updated_at).
     *
     * @param  array<int, int>  $ids
     */
    public function setBiometricStatus(array $ids, BiometricStatus $status): void;
}
