<?php

namespace App\Repositories\Contracts;

use App\Models\TenantImage;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<TenantImage>
 */
interface TenantImageRepositoryInterface extends RepositoryInterface
{
    /**
     * Library images, newest first, each with the number of templates using it (`templates_count`).
     *
     * @return Collection<int, TenantImage>
     */
    public function list(?string $search): Collection;

    /**
     * Find an image regardless of the current tenant (used by signed image links).
     */
    public function findAnyTenant(string $publicId): ?TenantImage;

    /**
     * Internal ids for public ids of the current organization (unknown ones are left out).
     *
     * @param  array<int, string>  $publicIds
     * @return array<string, int> public id => id
     */
    public function idsForPublicIds(array $publicIds): array;

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, TenantImage> keyed by id
     */
    public function findManyById(array $ids): Collection;
}
