<?php

namespace App\Services\Cardholders;

use App\Data\Cardholders\CardholderSyncFilter;
use App\Models\CardholderSync;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Contracts\CardholderSyncRepositoryInterface;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * What the HR sync did and what it takes: the run log (who sent what kind of request, when, how many of each
 * result; never what was sent), the fields a record may hold with the organization's own HR names, and deleting
 * old runs for the organizations that chose it.
 */
class CardholderSyncLogService
{
    public function __construct(
        private CardholderSyncRepositoryInterface $syncs,
        private TenantRepositoryInterface $tenants,
        private CardholderSyncMapper $mapper,
        private TenantContext $tenantContext,
    ) {}

    /**
     * The runs a person may see: all of them with integrations.manage, else (an HR system's account) its own.
     *
     * @param  array<string, mixed>  $input  validated request input
     * @return LengthAwarePaginator<int, CardholderSync>
     */
    public function list(User $viewer, array $input): LengthAwarePaginator
    {
        return $this->syncs->list(CardholderSyncFilter::fromArray($input, $viewer->can('integrations.manage') ? null : $viewer->id));
    }

    /**
     * Every field a record may hold, named in the reader's language, and the organization's HR names for them.
     *
     * @return array{fields: list<array{field: string, label: string}>, sync_fields: array<string, string>}
     */
    public function fields(): array
    {
        $names = [...(array) __('cardholders.sync_targets'), ...(array) __('cardholders.fields'), ...(array) __('reports.fields')];

        return [
            'fields' => array_map(fn (string $field): array => [
                'field' => $field,
                'label' => is_string($names[$field] ?? null) ? ucfirst($names[$field]) : $field,
            ], $this->mapper->targets()),
            'sync_fields' => $this->tenantContext->get()?->integrationSettings()['sync_fields'] ?? [],
        ];
    }

    /**
     * Delete runs older than each organization keeps them, for the organizations that chose that (off by default),
     * noted in their audit log.
     *
     * @return array<string, int> organization slug => runs deleted
     */
    public function prune(?Tenant $only = null): array
    {
        $pruned = [];
        $prune = function (Tenant $tenant) use (&$pruned): void {
            $settings = $tenant->integrationSettings();
            if (! $settings['sync_prune_log']) {
                return;
            }
            $count = $this->syncs->deleteOlderThan($tenant->id, now()->subDays($settings['sync_log_days']));
            $pruned[$tenant->slug] = $count;
            if ($count > 0) {
                // By the system, about the organization itself (its audit page shows such entries)
                activity('hr_sync')
                    ->performedOn($tenant)
                    ->causedByAnonymous()
                    ->event('pruned')
                    ->withProperties(['runs' => $count, 'days' => $settings['sync_log_days']])
                    ->log('pruned');
            }
        };

        $only === null ? $this->tenants->each($prune) : $prune($only);

        return $pruned;
    }
}
