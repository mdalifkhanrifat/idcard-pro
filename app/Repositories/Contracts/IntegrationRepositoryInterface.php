<?php

namespace App\Repositories\Contracts;

use App\Data\Integrations\IntegrationFilter;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<User>
 */
interface IntegrationRepositoryInterface extends RepositoryInterface
{
    /**
     * Integration accounts of the current tenant.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function list(IntegrationFilter $filter): LengthAwarePaginator;

    /**
     * The account with its role, its tokens still in force and their last use.
     */
    public function withSummary(User $account): User;
}
