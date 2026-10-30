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
}
