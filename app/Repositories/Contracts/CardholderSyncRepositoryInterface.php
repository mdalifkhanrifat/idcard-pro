<?php

namespace App\Repositories\Contracts;

use App\Data\Cardholders\CardholderSyncFilter;
use App\Models\CardholderSync;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<CardholderSync>
 */
interface CardholderSyncRepositoryInterface extends RepositoryInterface
{
    /**
     * The organization's runs, newest first, with the account that sent each.
     *
     * @return LengthAwarePaginator<int, CardholderSync>
     */
    public function list(CardholderSyncFilter $filter): LengthAwarePaginator;

    /**
     * Delete an organization's runs started before a moment (a run is kept from its start: created_at, indexed), in chunks; how many went.
     */
    public function deleteOlderThan(int $tenantId, CarbonInterface $before, int $chunk = 1000): int;
}
