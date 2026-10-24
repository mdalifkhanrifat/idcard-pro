<?php

namespace App\Repositories\Contracts;

interface NotificationOnceRepositoryInterface
{
    /**
     * Take the right to send this scheduled message (the event, what it is about, which one): true for the one run
     * that records it first, false when it was sent already — also by another run at the same time.
     */
    public function claim(int $tenantId, string $event, int $subjectId, string $key): bool;
}
