<?php

namespace App\Repositories\Contracts;

use App\Data\Webhooks\WebhookDeliveryFilter;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<WebhookDelivery>
 */
interface WebhookDeliveryRepositoryInterface extends RepositoryInterface
{
    /**
     * From the queue, where no organization is known yet: with its organization.
     */
    public function findForDelivery(int $id): ?WebhookDelivery;

    /**
     * @return LengthAwarePaginator<int, WebhookDelivery>
     */
    public function listFor(Webhook $webhook, WebhookDeliveryFilter $filter): LengthAwarePaginator;

    public function withDetails(WebhookDelivery $delivery): WebhookDelivery;

    /**
     * Inside a transaction: nothing can send this one again until it ends.
     */
    public function lockForResend(WebhookDelivery $delivery): WebhookDelivery;
}
