<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Biometrics\BiometricStatusService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:biometric-status {tenant? : Organization slug; all organizations when left out}')]
#[Description('Work out every person\'s biometric status again from their captures and the organization\'s settings')]
class BiometricStatusCommand extends Command
{
    public function handle(BiometricStatusService $statuses, TenantContext $tenantContext): int
    {
        $tenants = Tenant::query()->when($this->argument('tenant'), fn ($query, $slug) => $query->where('slug', $slug))->get();
        if ($tenants->isEmpty()) {
            $this->components->error('No such organization.');

            return self::FAILURE;
        }
        foreach ($tenants as $tenant) {
            $checked = $tenantContext->run($tenant, fn () => $statuses->refreshAll($tenant));
            $this->components->info("{$tenant->slug}: {$checked} people checked.");
        }

        return self::SUCCESS;
    }
}
