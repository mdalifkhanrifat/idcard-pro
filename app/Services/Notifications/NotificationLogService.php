<?php

namespace App\Services\Notifications;

use App\Models\Tenant;
use App\Repositories\Contracts\NotificationMessageRepositoryInterface;
use App\Repositories\Contracts\TenantRepositoryInterface;

/**
 * Keeping the log of sent messages: old messages leave it only for organizations that chose so
 * (settings.notifications.prune_log), after their own number of days; each deletion is noted in the audit log.
 */
class NotificationLogService
{
    public function __construct(
        private NotificationMessageRepositoryInterface $messages,
        private TenantRepositoryInterface $tenants,
    ) {}

    /**
     * Delete old messages for every organization that has them deleted (or one); returns how many per
     * organization (slug => count), organizations that keep their log left out.
     *
     * @return array<string, int>
     */
    public function prune(?Tenant $only = null): array
    {
        $pruned = [];
        $prune = function (Tenant $tenant) use (&$pruned): void {
            $settings = $tenant->notificationSettings();
            if (! $settings['prune_log']) {
                return;
            }
            $count = $this->messages->deleteOlderThan($tenant->id, now()->subDays($settings['log_days']));
            $pruned[$tenant->slug] = $count;
            if ($count > 0) {
                // By the system, about the organization itself (its audit page shows such entries)
                activity('notifications')
                    ->performedOn($tenant)
                    ->causedByAnonymous()
                    ->event('pruned')
                    ->withProperties(['messages' => $count, 'days' => $settings['log_days']])
                    ->log('pruned');
            }
        };

        $only === null ? $this->tenants->each($prune) : $prune($only);

        return $pruned;
    }
}
