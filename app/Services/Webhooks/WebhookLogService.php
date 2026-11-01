<?php

namespace App\Services\Webhooks;

use App\Models\Tenant;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\Contracts\WebhookDeliveryRepositoryInterface;

/**
 * The log of deliveries is kept for good unless an organization chooses to have old ones deleted
 * (settings.webhooks.prune_log), after its own number of days; each deletion is noted in its audit log.
 */
class WebhookLogService
{
    public function __construct(private WebhookDeliveryRepositoryInterface $deliveries, private TenantRepositoryInterface $tenants) {}

    /**
     * Delete old deliveries for every organization that has them deleted (or one); returns how many per organization
     * (slug => count), organizations that keep their log left out.
     *
     * @return array<string, int>
     */
    public function prune(?Tenant $only = null): array
    {
        $pruned = [];
        $prune = function (Tenant $tenant) use (&$pruned): void {
            $settings = $tenant->webhookSettings();
            if (! $settings['prune_log']) {
                return;
            }
            $count = $this->deliveries->deleteOlderThan($tenant->id, now()->subDays($settings['log_days']));
            $pruned[$tenant->slug] = $count;
            if ($count > 0) {
                // By the system, about the organization itself (its audit page shows such entries)
                activity('webhooks')
                    ->performedOn($tenant)
                    ->causedByAnonymous()
                    ->event('pruned')
                    ->withProperties(['deliveries' => $count, 'days' => $settings['log_days']])
                    ->log('pruned');
            }
        };

        $only === null ? $this->tenants->each($prune) : $prune($only);

        return $pruned;
    }
}
