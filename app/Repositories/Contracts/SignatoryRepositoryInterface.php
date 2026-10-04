<?php

namespace App\Repositories\Contracts;

use App\Models\MasterDataItem;
use App\Models\Signatory;
use Carbon\CarbonInterface;
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

    /**
     * The active signatory of an authority type whose effective dates cover the given day (latest first).
     */
    public function activeFor(int $authorityTypeId, CarbonInterface $on): ?Signatory;

    public function usesMasterDataItem(MasterDataItem $item): bool;
}
