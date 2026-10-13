<?php

namespace App\Repositories\Eloquent;

use App\Data\Signatories\SignatoryFilter;
use App\Models\MasterDataItem;
use App\Models\Signatory;
use App\Repositories\Contracts\SignatoryRepositoryInterface;
use App\Support\ListPage;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * @extends EloquentRepository<Signatory>
 */
class SignatoryRepository extends EloquentRepository implements SignatoryRepositoryInterface
{
    protected function model(): string
    {
        return Signatory::class;
    }

    public function list(?string $search, ?bool $isActive): Collection
    {
        return $this->criteria(new SignatoryFilter($search, $isActive))
            ->with('authorityType')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    public function report(SignatoryFilter $filter): LengthAwarePaginator
    {
        return $this->reportQuery($filter)->paginate(ListPage::clamp($filter->perPage))->withQueryString();
    }

    public function reportRows(SignatoryFilter $filter): LazyCollection
    {
        return $this->reportQuery($filter)->lazy(200);
    }

    public function reportTotals(SignatoryFilter $filter): array
    {
        $totals = $this->criteria($filter)->toBase()->selectRaw('count(*) as signatories, coalesce(sum(case when is_active then 1 else 0 end), 0) as active')->first();

        return ['signatories' => (int) ($totals->signatories ?? 0), 'active' => (int) ($totals->active ?? 0)];
    }

    /**
     * @return Builder<Signatory>
     */
    private function reportQuery(SignatoryFilter $filter): Builder
    {
        return $this->criteria($filter)
            ->with('authorityType')
            ->orderBy($filter->sort, $filter->descending ? 'desc' : 'asc')
            ->orderBy('id');
    }

    /**
     * The list page's criteria (and the report's authority type), without order.
     *
     * @return Builder<Signatory>
     */
    private function criteria(SignatoryFilter $filter): Builder
    {
        return $this->query()
            ->when($filter->search, fn (Builder $q, string $value) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$value}%")
                ->orWhere('name_local', 'like', "%{$value}%")
                ->orWhere('designation', 'like', "%{$value}%")))
            ->when($filter->isActive !== null, fn (Builder $q) => $q->where('is_active', $filter->isActive))
            ->when($filter->authorityTypePublicId, fn (Builder $q, string $id) => $q->whereIn('authority_type_id', MasterDataItem::query()->where('public_id', $id)->select('id')));
    }

    public function findAnyTenant(string $publicId): ?Signatory
    {
        return Signatory::query()->withoutGlobalScopes()->where('public_id', $publicId)->first();
    }

    public function activeFor(int $authorityTypeId, CarbonInterface $on): ?Signatory
    {
        $day = $on->toDateString();

        return $this->query()
            ->where('authority_type_id', $authorityTypeId)
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    public function usesMasterDataItem(MasterDataItem $item): bool
    {
        return $item->type === 'authority_type' && $this->query()->where('authority_type_id', $item->id)->exists();
    }
}
