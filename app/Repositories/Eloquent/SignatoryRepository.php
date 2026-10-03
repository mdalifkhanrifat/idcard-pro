<?php

namespace App\Repositories\Eloquent;

use App\Models\MasterDataItem;
use App\Models\Signatory;
use App\Repositories\Contracts\SignatoryRepositoryInterface;
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

    public function usesMasterDataItem(MasterDataItem $item): bool
    {
        return $item->type === 'authority_type' && $this->query()->where('authority_type_id', $item->id)->exists();
    }
}
