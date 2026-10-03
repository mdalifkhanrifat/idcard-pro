<?php

namespace App\Console\Commands;

use App\Support\Permissions\PermissionRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:sync-permissions')]
#[Description('Write the permission registry (config/permissions.php) to the database')]
class SyncPermissions extends Command
{
    public function handle(PermissionRegistry $registry): int
    {
        $this->components->info("Synced {$registry->sync()} permissions.");

        return self::SUCCESS;
    }
}
