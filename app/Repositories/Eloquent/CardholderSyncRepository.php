<?php

namespace App\Repositories\Eloquent;

use App\Data\Cardholders\CardholderSyncFilter;
use App\Enums\CardholderSyncKind;
use App\Models\CardholderSync;
use App\Repositories\Contracts\CardholderSyncRepositoryInterface;
use App\Support\ListPage;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends EloquentRepository<CardholderSync>
 */
class CardholderSyncRepository extends EloquentRepository implements CardholderSyncRepositoryInterface
{
    protected function model(): string
    {
        return CardholderSync::class;
    }

    public function list(CardholderSyncFilter $filter): LengthAwarePaginator
    {
        return $this->query()
            ->with('user')
            ->when($filter->kind, fn (Builder $q, CardholderSyncKind $kind) => $q->where('kind', $kind))
            ->when($filter->userId, fn (Builder $q, int $userId) => $q->where('user_id', $userId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(ListPage::clamp($filter->perPage))
            ->withQueryString();
    }

    public function deleteOlderThan(int $tenantId, CarbonInterface $before, int $chunk = 1000): int
    {
        $deleted = 0;
        do {
            $ids = CardholderSync::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('created_at', '<', $before)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : CardholderSync::query()->withoutGlobalScopes()->whereIn('id', $ids)->delete();
        } while ($ids->count() === $chunk);

        return $deleted;
    }
}
