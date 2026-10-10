<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Biometrics\BiometricStatusService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Works out every person's biometric status again after an organization changed its biometric settings.
 * One run per organization at a time; a later change queues another run.
 */
class RefreshBiometricStatuses implements ShouldBeUnique, ShouldQueue
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
