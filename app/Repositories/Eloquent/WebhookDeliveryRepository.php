<?php

namespace App\Repositories\Eloquent;

use App\Data\Webhooks\WebhookDeliveryFilter;
use App\Enums\WebhookDeliveryStatus;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Repositories\Contracts\WebhookDeliveryRepositoryInterface;
use App\Support\ListPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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

    public function listFor(Webhook $webhook, WebhookDeliveryFilter $filter): LengthAwarePaginator
    {
        return $this->query()
            ->where('webhook_id', $webhook->id)
            ->when($filter->status, fn (Builder $q, WebhookDeliveryStatus $status) => $q->where('status', $status))
            ->when($filter->event, fn (Builder $q, string $event) => $q->where('event', $event))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(ListPage::clamp($filter->perPage))
            ->withQueryString();
    }
}
