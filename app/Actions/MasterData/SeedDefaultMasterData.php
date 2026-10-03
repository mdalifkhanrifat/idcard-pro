<?php

namespace App\Actions\MasterData;

use App\Models\MasterDataItem;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * Ensures a tenant has every default item declared in config/master_data.php.
 * Safe to run repeatedly; existing items are left untouched.
 */
class SeedDefaultMasterData
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @return int Number of items created.
     */
    public function handle(Tenant $tenant): int
    {
        return $this->tenantContext->run($tenant, function (): int {
            $created = 0;

            foreach (config('master_data.types') as $type => $definition) {
                foreach ($definition['defaults'] ?? [] as $position => $name) {
                    $item = MasterDataItem::query()->firstOrCreate(
                        ['type' => $type, 'parent_id' => null, 'name' => $name],
                        ['sort_order' => $position],
                    );
                    $created += (int) $item->wasRecentlyCreated;
                }
            }

            return $created;
        });
    }
}
