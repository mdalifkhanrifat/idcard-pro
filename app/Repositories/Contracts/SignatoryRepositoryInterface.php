<?php

namespace App\Repositories\Contracts;

use App\Models\MasterDataItem;
use App\Models\Signatory;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<Signatory>
 */
interface SignatoryRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<int, Signatory>
     */
    public function list(?string $search, ?bool $isActive): Collection;

    /**
     * Find a signatory regardless of the current tenant (used by signed signature links).
     */
    public function findAnyTenant(string $publicId): ?Signatory;

    public function usesMasterDataItem(MasterDataItem $item): bool;
}
