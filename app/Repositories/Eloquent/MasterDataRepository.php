<?php

namespace App\Repositories\Eloquent;

use App\Data\MasterData\MasterDataFilter;
use App\Models\MasterDataItem;
use App\Repositories\Contracts\MasterDataRepositoryInterface;
use App\Support\ListPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

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
            : $query->paginate(ListPage::clamp($filter->perPage))->withQueryString();
    }

    public function report(?string $type, MasterDataFilter $filter): LengthAwarePaginator
    {
        return $this->withReportRelations($this->reportQuery($type, $filter))->paginate(ListPage::clamp((int) $filter->perPage))->withQueryString();
    }

    public function reportRows(?string $type, MasterDataFilter $filter): LazyCollection
    {
        return $this->withReportRelations($this->reportQuery($type, $filter))->lazy(500);
    }

    public function reportTotals(?string $type, MasterDataFilter $filter): array
    {
        $totals = $this->reportQuery($type, $filter)->reorder()->toBase()
            ->selectRaw('count(*) as items, coalesce(sum(case when is_active then 1 else 0 end), 0) as active')
            ->first();

        return ['items' => (int) ($totals->items ?? 0), 'active' => (int) ($totals->active ?? 0)];
    }

    /**
     * The parent and how many items are inside: not in the totals' query (MySQL refuses a count beside them).
     *
     * @param  Builder<MasterDataItem>  $query
     * @return Builder<MasterDataItem>
     */
    private function withReportRelations(Builder $query): Builder
    {
        return $query->with('parent')->withCount('children');
    }

    /**
     * Items of one list, or of every list grouped by list, with the list page's own criteria.
     *
     * @return Builder<MasterDataItem>
     */
    private function reportQuery(?string $type, MasterDataFilter $filter): Builder
    {
        return $this->query()
            ->when($type, fn (Builder $q, string $value) => $q->where('type', $value), fn (Builder $q) => $q->orderBy('type'))
            ->when($filter->search, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_local', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")))
            ->when($filter->parentPublicId, fn (Builder $q, string $parent) => $q->whereIn('parent_id', $this->query()->where('public_id', $parent)->select('id')))
            ->when($filter->isActive !== null, fn (Builder $q) => $q->where('is_active', $filter->isActive))
            ->orderBy($filter->sort, $filter->descending ? 'desc' : 'asc')
            ->orderBy('name')
            ->orderBy('id');
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

    public function idsForPublicIds(array $publicIds): array
    {
        if ($publicIds === []) {
            return [];
        }

        return $this->query()->whereIn('public_id', $publicIds)->pluck('id', 'public_id')->all();
    }

    public function allOfType(string $type): Collection
    {
        return $this->query()->where('type', $type)->orderBy('id')->get();
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
