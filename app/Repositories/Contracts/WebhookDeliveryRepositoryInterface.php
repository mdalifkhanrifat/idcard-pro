<?php

namespace App\Repositories\Contracts;

use App\Models\WebhookDelivery;

/**
 * @extends RepositoryInterface<WebhookDelivery>
 */
interface WebhookDeliveryRepositoryInterface extends RepositoryInterface
{
    /**
     * From the queue, where no organization is known yet: with its organization.
     */
    public function findForDelivery(int $id): ?WebhookDelivery;
}
