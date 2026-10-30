<?php

namespace App\Repositories\Eloquent;

use App\Data\Webhooks\WebhookFilter;
use App\Models\Webhook;
use App\Repositories\Contracts\WebhookRepositoryInterface;
use App\Support\ListPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<Webhook>
 */
class WebhookRepository extends EloquentRepository implements WebhookRepositoryInterface
{
    protected function model(): string
    {
        return Webhook::class;
    }

    public function list(WebhookFilter $filter): LengthAwarePaginator
    {
        return $this->query()
            ->when($filter->search, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('url', 'like', "%{$search}%")))
            ->when($filter->enabled !== null, fn (Builder $q) => $q->where('enabled', $filter->enabled))
            ->orderBy($filter->sort, $filter->descending ? 'desc' : 'asc')
            ->orderBy('id', 'desc')
            ->paginate(ListPage::clamp($filter->perPage))
            ->withQueryString();
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    public function listeningTo(string $event): Collection
    {
        // An organization has a handful at most: read here rather than one JSON query per database
        return $this->query()->where('enabled', true)->get()
            ->filter(fn (Webhook $webhook): bool => in_array($event, $webhook->events, true))
            ->values()
            ->toBase();
    }

    public function lockForFailure(int $id): Webhook
    {
        return $this->query()->withTrashed()->lockForUpdate()->findOrFail($id);
    }
}
