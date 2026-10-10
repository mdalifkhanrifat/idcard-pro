<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Biometrics\BiometricStatusService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Works out every person's biometric status again after an organization changed its biometric settings.
 * At most one waiting run per organization; the lock is released when a run starts, so a change made
 * while it runs (which it may not have seen) queues another one.
 */
class RefreshBiometricStatuses implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(public int $tenantId) {}

    public function uniqueId(): string
    {
        return (string) $this->tenantId;
    }

    public function handle(BiometricStatusService $statuses, TenantContext $tenantContext): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant !== null) {
            $tenantContext->run($tenant, fn () => $statuses->refreshAll($tenant));
        }
    }
}
