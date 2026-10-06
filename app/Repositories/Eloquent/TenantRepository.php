<?php

namespace App\Repositories\Eloquent;

use App\Data\Tenants\TenantFilter;
use App\Models\Tenant;
use App\Repositories\Contracts\TenantRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends EloquentRepository<Tenant>
 */
class TenantRepository extends EloquentRepository implements TenantRepositoryInterface
{
    protected function model(): string
    {
        return Tenant::class;
    }

    public function list(TenantFilter $filter): LengthAwarePaginator
    {
        return $this->query()
            ->withCount('users')
            ->when($filter->name, fn (Builder $q, string $name) => $q->where('name', 'like', "%{$name}%"))
            ->when($filter->slug, fn (Builder $q, string $slug) => $q->where('slug', $slug))
            ->when($filter->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->orderBy($filter->sort, $filter->descending ? 'desc' : 'asc')
            ->paginate(min($filter->perPage, 100))
            ->withQueryString();
    }

    public function findBySlug(string $slug): ?Tenant
    {
        return $this->query()->where('slug', strtolower(trim($slug)))->first();
    }

    public function findByPublicId(string $publicId): ?Tenant
    {
        return $this->query()->where('public_id', $publicId)->first();
    }

    public function each(callable $callback): void
    {
        $this->query()->each($callback);
    }
}
