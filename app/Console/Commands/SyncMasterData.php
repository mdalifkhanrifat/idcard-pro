<?php

namespace App\Console\Commands;

use App\Actions\MasterData\SeedDefaultMasterData;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:sync-master-data')]
#[Description('Add default master data items (config/master_data.php) missing from existing organizations')]
class SyncMasterData extends Command
{
    public function handle(SeedDefaultMasterData $seed): int
    {
        $total = 0;
        Tenant::query()->each(function (Tenant $tenant) use ($seed, &$total): void {
            $total += $seed->handle($tenant);
        });

        $this->components->info("Created {$total} default items.");

        return self::SUCCESS;
    }
}
