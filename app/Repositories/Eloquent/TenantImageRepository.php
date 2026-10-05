<?php

namespace App\Repositories\Eloquent;

use App\Models\CardTemplateElement;
use App\Models\TenantImage;
use App\Repositories\Contracts\TenantImageRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<TenantImage>
 */
class TenantImageRepository extends EloquentRepository implements TenantImageRepositoryInterface
{
    protected function model(): string
    {
        return TenantImage::class;
    }

    public function list(?string $search): Collection
    {
        return $this->query()
            ->addSelect(['templates_count' => CardTemplateElement::query()
                ->selectRaw('count(distinct card_template_id)')
                ->whereColumn('library_image_id', 'tenant_images.id')])
            ->when($search, fn (Builder $q, string $value) => $q->where('name', 'like', "%{$value}%"))
            ->latest('id')
            ->get();
    }

    public function findAnyTenant(string $publicId): ?TenantImage
    {
        return TenantImage::query()->withoutGlobalScopes()->where('public_id', $publicId)->first();
    }

    public function findManyById(array $ids): Collection
    {
        return $ids === [] ? collect() : $this->query()->whereKey($ids)->get()->keyBy('id');
    }

    public function idsForPublicIds(array $publicIds): array
    {
        return $publicIds === [] ? [] : $this->query()->whereIn('public_id', $publicIds)->pluck('id', 'public_id')->all();
    }
}
