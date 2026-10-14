<?php

namespace App\Console\Commands;

use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Services\Notifications\NotificationLogService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:prune-notification-log {tenant? : Organization slug; all organizations when left out}')]
#[Description('Delete sent messages older than each organization keeps them, for organizations that chose to have them deleted')]
class PruneNotificationLogCommand extends Command
{
    public function handle(NotificationLogService $log, TenantRepositoryInterface $tenants): int
    {
        $slug = $this->argument('tenant');
        $tenant = is_string($slug) ? $tenants->findBySlug($slug) : null;
        if (is_string($slug) && $tenant === null) {
            $this->components->error('No such organization.');

            return self::FAILURE;
        }

        $pruned = $log->prune($tenant);
        foreach ($pruned as $organization => $count) {
            $this->components->info("{$organization}: {$count} old messages deleted.");
        }
        if ($pruned === []) {
            $this->components->info('No organization has old messages deleted; every log is kept.');
        }

        return self::SUCCESS;
    }
}
