<?php

namespace App\Repositories\Contracts;

use App\Data\Cardholders\CardholderFilter;
use App\Enums\BiometricStatus;
use App\Models\Cardholder;
use App\Models\MasterDataItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<Cardholder>
 */
interface CardholderRepositoryInterface extends RepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, Cardholder>
     */
    public function list(CardholderFilter $filter): LengthAwarePaginator;

    /**
     * Whether any cardholder references the master data item.
     */
    public function usesMasterDataItem(MasterDataItem $item): bool;

    public function findByEmployeeNo(string $employeeNo): ?Cardholder;

    /**
     * Keep the biometric status of cardholders; not an edit of the person (no audit entry, no updated_at).
     *
     * @param  array<int, int>  $ids
     */
    public function setBiometricStatus(array $ids, BiometricStatus $status): void;

    /**
     * Every cardholder of the organization in batches, with their family members' ids.
     *
     * @param  callable(Collection<int, Cardholder>): void  $callback
     */
    public function eachBatch(int $size, callable $callback): void;
}
