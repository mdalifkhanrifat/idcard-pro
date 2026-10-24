<?php

namespace App\Repositories\Eloquent;

use App\Models\NotificationOnce;
use App\Repositories\Contracts\NotificationOnceRepositoryInterface;

class NotificationOnceRepository implements NotificationOnceRepositoryInterface
{
    public function claim(int $tenantId, string $event, int $subjectId, string $key): bool
    {
        // The unique key decides between two runs: only one insert takes
        return NotificationOnce::query()->insertOrIgnore([
            'tenant_id' => $tenantId,
            'event' => $event,
            'subject_id' => $subjectId,
            'key' => $key,
            'created_at' => now()->utc()->format('Y-m-d H:i:s'),
        ]) === 1;
    }
}
