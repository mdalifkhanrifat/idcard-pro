<?php

namespace App\Repositories\Contracts;

use App\Data\MasterData\MasterDataFilter;
use App\Models\MasterDataItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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
     * @return array<string, int> type => number of items
     */
    public function countsByType(): array;

    public function idForPublicId(string $publicId): ?int;

    public function hasChildren(MasterDataItem $item): bool;

    /**
     * Create a top-level item unless one with the same name already exists.
     *
     * @return bool whether a new item was created
     */
    public function createIfMissing(string $type, string $name, int $sortOrder): bool;
}
