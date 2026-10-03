<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\MasterData\MasterDataService;
use App\Services\Tenants\TenantService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:sync-master-data')]
#[Description('Add default master data items (config/master_data.php) missing from existing organizations')]
class SyncMasterData extends Command
{
    public function handle(MasterDataService $masterData, TenantService $tenants): int
    {
        $total = 0;
        $tenants->each(function (Tenant $tenant) use ($masterData, &$total): void {
            $total += $masterData->seedDefaults($tenant);
        });

        $this->components->info("Created {$total} default items.");

        return self::SUCCESS;
    }
}
