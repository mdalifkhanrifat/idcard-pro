<?php

namespace App\Repositories\Contracts;

use App\Data\Tenants\TenantFilter;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<Tenant>
 */
interface TenantRepositoryInterface extends RepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, Tenant>
     */
    public function list(TenantFilter $filter): LengthAwarePaginator;

    public function findBySlug(string $slug): ?Tenant;

    public function findByPublicId(string $publicId): ?Tenant;

    /**
     * Run the callback for every tenant, loading them in chunks.
     *
     * @param  callable(Tenant): void  $callback
     */
    public function each(callable $callback): void;
}
