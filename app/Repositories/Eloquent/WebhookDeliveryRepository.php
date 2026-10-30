<?php

namespace App\Repositories\Eloquent;

use App\Models\WebhookDelivery;
use App\Repositories\Contracts\WebhookDeliveryRepositoryInterface;

/**
 * @extends EloquentRepository<WebhookDelivery>
 */
class WebhookDeliveryRepository extends EloquentRepository implements WebhookDeliveryRepositoryInterface
{
    protected function model(): string
    {
        return WebhookDelivery::class;
    }

    public function findForDelivery(int $id): ?WebhookDelivery
    {
        return WebhookDelivery::query()->withoutGlobalScopes()->with('tenant')->find($id);
    }
}
