<?php

namespace App\Repositories\Contracts;

use App\Data\CardTemplates\CardTemplateFilter;
use App\Models\CardTemplate;
use App\Models\MasterDataItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * @extends RepositoryInterface<CardTemplate>
 */
interface CardTemplateRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<int, CardTemplate>
     */
    public function list(CardTemplateFilter $filter): Collection;

    /**
     * Templates of the report with their card type, authority type and how many cards were issued from each.
     *
     * @return LengthAwarePaginator<int, CardTemplate>
     */
    public function report(CardTemplateFilter $filter): LengthAwarePaginator;

    /**
     * @return LazyCollection<int, CardTemplate>
     */
    public function reportRows(CardTemplateFilter $filter): LazyCollection;

    /**
     * @return array{templates: int, active: int}
     */
    public function reportTotals(CardTemplateFilter $filter): array;

    public function usesMasterDataItem(MasterDataItem $item): bool;

    /**
     * Lookup without the tenant scope, for signed image links (no session).
     */
    public function findAnyTenant(string $publicId): ?CardTemplate;

    /**
     * Raise the version of templates whose printed design changed outside the designer.
     *
     * @param  array<int, int>  $ids
     */
    public function incrementVersions(array $ids): void;
}
