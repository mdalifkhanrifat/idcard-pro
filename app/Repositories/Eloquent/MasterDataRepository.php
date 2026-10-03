<?php

namespace App\Repositories\Eloquent;

use App\Data\MasterData\MasterDataFilter;
use App\Models\MasterDataItem;
use App\Repositories\Contracts\MasterDataRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<MasterDataItem>
 */
class MasterDataRepository extends EloquentRepository implements MasterDataRepositoryInterface
{
    protected function model(): string
    {
        return MasterDataItem::class;
    }

    public function list(string $type, MasterDataFilter $filter): LengthAwarePaginator|Collection
    {
        $query = $this->query()
            ->where('type', $type)
            ->with('parent')
            ->withCount('children')
            ->when($filter->search, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_local', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")))
            ->when($filter->parentPublicId, fn (Builder $q, string $parent) => $q->whereIn(
                'parent_id',
                $this->query()->where('public_id', $parent)->select('id'),
            ))
            ->when($filter->isActive !== null, fn (Builder $q) => $q->where('is_active', $filter->isActive))
            ->orderBy($filter->sort, $filter->descending ? 'desc' : 'asc')
            ->orderBy('name');

        return $filter->perPage === null
            ? $query->get()
            : $query->paginate(min($filter->perPage, 100))->withQueryString();
    }

    public function countsByType(): array
    {
        return $this->query()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    public function idForPublicId(string $publicId): ?int
    {
        return $this->query()->where('public_id', $publicId)->value('id');
    }

    public function hasChildren(MasterDataItem $item): bool
    {
        return $item->children()->exists();
    }

    public function createIfMissing(string $type, string $name, int $sortOrder): bool
    {
        return $this->query()
            ->firstOrCreate(['type' => $type, 'parent_id' => null, 'name' => $name], ['sort_order' => $sortOrder])
            ->wasRecentlyCreated;
    }
}
