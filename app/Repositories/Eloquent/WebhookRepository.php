<?php

namespace App\Repositories\Eloquent;

use App\Data\Webhooks\WebhookFilter;
use App\Models\Webhook;
use App\Repositories\Contracts\WebhookRepositoryInterface;
use App\Support\ListPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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
}
