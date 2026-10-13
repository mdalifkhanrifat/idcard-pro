<?php

namespace App\Repositories\Contracts;

use App\Data\Reports\FamilyMemberReportFilter;
use App\Enums\BiometricStatus;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\MasterDataItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

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
     * Family members of the report with everything a row reads (member, card, cardholder).
     *
     * @return LengthAwarePaginator<int, FamilyMember>
     */
    public function report(FamilyMemberReportFilter $filter): LengthAwarePaginator;

    /**
     * @return LazyCollection<int, FamilyMember>
     */
    public function reportRows(FamilyMemberReportFilter $filter): LazyCollection;

    /**
     * @return array{members: int, cardholders: int}
     */
    public function reportTotals(FamilyMemberReportFilter $filter): array;

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
