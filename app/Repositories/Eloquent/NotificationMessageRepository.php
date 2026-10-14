<?php

namespace App\Repositories\Eloquent;

use App\Models\NotificationMessage;
use App\Repositories\Contracts\NotificationMessageRepositoryInterface;
use Carbon\CarbonInterface;

/**
 * @extends EloquentRepository<NotificationMessage>
 */
class NotificationMessageRepository extends EloquentRepository implements NotificationMessageRepositoryInterface
{
    protected function model(): string
    {
        return NotificationMessage::class;
    }

    public function deleteOlderThan(int $tenantId, CarbonInterface $before, int $chunk = 1000): int
    {
        $deleted = 0;
        do {
            $ids = NotificationMessage::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('created_at', '<', $before)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : NotificationMessage::query()->withoutGlobalScopes()->whereIn('id', $ids)->delete();
        } while ($ids->count() === $chunk);

        return $deleted;
    }
}
