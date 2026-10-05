<?php

namespace App\Repositories\Eloquent;

use App\Data\CardTemplates\CardTemplateFilter;
use App\Models\CardTemplate;
use App\Models\MasterDataItem;
use App\Repositories\Contracts\CardTemplateRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
        return $this->query()
            ->with(['cardType', 'authorityType'])
            ->when($filter->withElements, fn (Builder $q) => $q->with('elements'))
            ->when($filter->search, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")))
            ->when($filter->cardTypePublicId, fn (Builder $q, string $type) => $q->whereIn(
                'card_type_id',
                MasterDataItem::query()->where('public_id', $type)->select('id'),
            ))
            ->when($filter->audience, fn (Builder $q, string $audience) => $q->where('audience', $audience))
            ->when($filter->isActive !== null, fn (Builder $q) => $q->where('is_active', $filter->isActive))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
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
