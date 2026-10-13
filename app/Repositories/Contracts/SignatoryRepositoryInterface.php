<?php

namespace App\Repositories\Contracts;

use App\Data\Signatories\SignatoryFilter;
use App\Models\MasterDataItem;
use App\Models\Signatory;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

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
     * Signatories of the report with their authority type.
     *
     * @return LengthAwarePaginator<int, Signatory>
     */
    public function report(SignatoryFilter $filter): LengthAwarePaginator;

    /**
     * @return LazyCollection<int, Signatory>
     */
    public function reportRows(SignatoryFilter $filter): LazyCollection;

    /**
     * @return array{signatories: int, active: int}
     */
    public function reportTotals(SignatoryFilter $filter): array;

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
