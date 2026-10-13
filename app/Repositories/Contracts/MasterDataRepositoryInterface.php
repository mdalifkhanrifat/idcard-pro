<?php

namespace App\Repositories\Contracts;

use App\Data\MasterData\MasterDataFilter;
use App\Models\MasterDataItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * @extends RepositoryInterface<MasterDataItem>
 */
interface MasterDataRepositoryInterface extends RepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, MasterDataItem>|Collection<int, MasterDataItem>
     */
    public function list(string $type, MasterDataFilter $filter): LengthAwarePaginator|Collection;

    /**
     * Items for the report: one list (`type`) or every list, with the list page's criteria.
     *
     * @return LengthAwarePaginator<int, MasterDataItem>
     */
    public function report(?string $type, MasterDataFilter $filter): LengthAwarePaginator;

    /**
     * @return LazyCollection<int, MasterDataItem>
     */
    public function reportRows(?string $type, MasterDataFilter $filter): LazyCollection;

    /**
     * @return array{items: int, active: int}
     */
    public function reportTotals(?string $type, MasterDataFilter $filter): array;

    /**
     * @return array<string, int> type => number of items
     */
    public function countsByType(): array;

    public function idForPublicId(string $publicId): ?int;

    /**
     * @param  array<int, string>  $publicIds
     * @return array<string, int> public id => id
     */
    public function idsForPublicIds(array $publicIds): array;

    /**
     * Every item of a type (active or not), for matching imported names.
     *
     * @return Collection<int, MasterDataItem>
     */
    public function allOfType(string $type): Collection;

    public function hasChildren(MasterDataItem $item): bool;

    /**
     * Create a top-level item unless one with the same name already exists.
     *
     * @return bool whether a new item was created
     */
    public function createIfMissing(string $type, string $name, int $sortOrder): bool;
}
