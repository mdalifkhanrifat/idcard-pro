<?php

namespace App\Console\Commands;

use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Services\Webhooks\WebhookLogService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:prune-webhook-deliveries {tenant? : Organization slug; all organizations when left out}')]
#[Description('Delete webhook deliveries older than each organization keeps them, for organizations that chose to have them deleted')]
class PruneWebhookDeliveriesCommand extends Command
{
    public function handle(WebhookLogService $log, TenantRepositoryInterface $tenants): int
    {
        $slug = $this->argument('tenant');
        $tenant = is_string($slug) ? $tenants->findBySlug($slug) : null;
        if (is_string($slug) && $tenant === null) {
            $this->components->error('No such organization.');

            return self::FAILURE;
        }

        $pruned = $log->prune($tenant);
        foreach ($pruned as $organization => $count) {
            $this->components->info("{$organization}: {$count} old deliveries deleted.");
        }
        if ($pruned === []) {
            $this->components->info('No organization has old deliveries deleted; every log is kept.');
        }

        return self::SUCCESS;
    }
}
