<?php

namespace App\Repositories\Eloquent;

use App\Models\MasterDataItem;
use App\Models\Signatory;
use App\Repositories\Contracts\SignatoryRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
        return $this->query()
            ->with('authorityType')
            ->when($search, fn (Builder $q, string $value) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$value}%")
                ->orWhere('name_local', 'like', "%{$value}%")
                ->orWhere('designation', 'like', "%{$value}%")))
            ->when($isActive !== null, fn (Builder $q) => $q->where('is_active', $isActive))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
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
