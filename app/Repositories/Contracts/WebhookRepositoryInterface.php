<?php

namespace App\Repositories\Contracts;

use App\Data\Webhooks\WebhookFilter;
use App\Models\Webhook;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<Webhook>
 */
interface WebhookRepositoryInterface extends RepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, Webhook>
     */
    public function list(WebhookFilter $filter): LengthAwarePaginator;

    public function count(): int;

    /**
     * The webhook held until the transaction ends, deleted or not: a failure is counted on it either way.
     */
    public function lockForFailure(int $id): Webhook;
}
