<?php

namespace App\Console\Commands;

use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Services\Cardholders\CardholderSyncLogService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:prune-sync-runs {tenant? : Organization slug; all organizations when left out}')]
#[Description('Delete HR sync runs older than each organization keeps them, for organizations that chose to have them deleted')]
class PruneSyncRunsCommand extends Command
{
    public function handle(CardholderSyncLogService $log, TenantRepositoryInterface $tenants): int
    {
        $slug = $this->argument('tenant');
        $tenant = is_string($slug) ? $tenants->findBySlug($slug) : null;
        if (is_string($slug) && $tenant === null) {
            $this->components->error('No such organization.');

            return self::FAILURE;
        }

        $pruned = $log->prune($tenant);
        foreach ($pruned as $organization => $count) {
            $this->components->info("{$organization}: {$count} old runs deleted.");
        }
        if ($pruned === []) {
            $this->components->info('No organization has old runs deleted; every log is kept.');
        }

        return self::SUCCESS;
    }
}
