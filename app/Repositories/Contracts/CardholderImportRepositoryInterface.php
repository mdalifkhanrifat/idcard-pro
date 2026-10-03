<?php

namespace App\Repositories\Contracts;

use App\Models\CardholderImport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<CardholderImport>
 */
interface CardholderImportRepositoryInterface extends RepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, CardholderImport>
     */
    public function latest(int $perPage): LengthAwarePaginator;

    /**
     * Find an import regardless of the current tenant (used by the queued job).
     */
    public function findAnyTenant(int $id): ?CardholderImport;
}
