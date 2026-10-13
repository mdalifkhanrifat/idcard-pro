<?php

namespace App\Repositories\Eloquent;

use App\Data\CardTemplates\CardTemplateFilter;
use App\Models\CardTemplate;
use App\Models\MasterDataItem;
use App\Repositories\Contracts\CardTemplateRepositoryInterface;
use App\Support\ListPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * @extends EloquentRepository<CardTemplate>
 */
class CardTemplateRepository extends EloquentRepository implements CardTemplateRepositoryInterface
{
    protected function model(): string
    {
        return CardTemplate::class;
    }

    public function list(CardTemplateFilter $filter): Collection
    {
        return $this->criteria($filter)
            ->with(['cardType', 'authorityType'])
            ->when($filter->withElements, fn (Builder $q) => $q->with('elements.libraryImage'))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    public function report(CardTemplateFilter $filter): LengthAwarePaginator
    {
        return $this->reportQuery($filter)->paginate(ListPage::clamp($filter->perPage))->withQueryString();
    }

    public function reportRows(CardTemplateFilter $filter): LazyCollection
    {
        return $this->reportQuery($filter)->lazy(200);
    }

    public function reportTotals(CardTemplateFilter $filter): array
    {
        $totals = $this->criteria($filter)->toBase()->selectRaw('count(*) as templates, coalesce(sum(case when is_active then 1 else 0 end), 0) as active')->first();

        return ['templates' => (int) ($totals->templates ?? 0), 'active' => (int) ($totals->active ?? 0)];
    }

    /**
     * @return Builder<CardTemplate>
     */
    private function reportQuery(CardTemplateFilter $filter): Builder
    {
        return $this->criteria($filter)
            ->with(['cardType', 'authorityType'])
            ->withCount('cards')
            ->orderBy($filter->sort, $filter->descending ? 'desc' : 'asc')
            ->orderBy('id');
    }

    /**
     * The list page's criteria, without order.
     *
     * @return Builder<CardTemplate>
     */
    private function criteria(CardTemplateFilter $filter): Builder
    {
        return $this->query()
            ->when($filter->search, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")))
            ->when($filter->cardTypePublicId, fn (Builder $q, string $type) => $q->whereIn(
                'card_type_id',
                MasterDataItem::query()->where('public_id', $type)->select('id'),
            ))
            ->when($filter->audience, fn (Builder $q, string $audience) => $q->where('audience', $audience))
            ->when($filter->isActive !== null, fn (Builder $q) => $q->where('is_active', $filter->isActive));
    }

    public function usesMasterDataItem(MasterDataItem $item): bool
    {
        $column = match ($item->type) {
            'card_type' => 'card_type_id',
            'authority_type' => 'authority_type_id',
            default => null,
        };

        return $column !== null && $this->query()->where($column, $item->id)->exists();
    }

    public function findAnyTenant(string $publicId): ?CardTemplate
    {
        return CardTemplate::query()->withoutGlobalScopes()->where('public_id', $publicId)->first();
    }

    public function incrementVersions(array $ids): void
    {
        if ($ids !== []) {
            $this->query()->whereKey($ids)->increment('version');
        }
    }
}
