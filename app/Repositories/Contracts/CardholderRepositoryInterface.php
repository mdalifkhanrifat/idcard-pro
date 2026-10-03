<?php

namespace App\Repositories\Contracts;

use App\Data\Cardholders\CardholderFilter;
use App\Models\Cardholder;
use App\Models\MasterDataItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
}
