<?php

namespace App\Jobs;

use App\Repositories\Contracts\CardholderImportRepositoryInterface;
use App\Services\Cardholders\CardholderImportService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs an analyzed cardholder import inside its organization.
 */
class ImportCardholders implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $importId) {}

    public function handle(CardholderImportRepositoryInterface $imports, CardholderImportService $service, TenantContext $tenantContext): void
    {
        $import = $imports->findAnyTenant($this->importId);
        if ($import === null) {
            return;
        }

        $tenantContext->run($import->tenant, fn () => $service->process($import));
    }

    public function failed(?Throwable $exception): void
    {
        $import = app(CardholderImportRepositoryInterface::class)->findAnyTenant($this->importId);
        if ($import !== null && in_array($import->status->value, ['queued', 'processing'], true)) {
            app(TenantContext::class)->run($import->tenant, fn () => app(CardholderImportService::class)
                ->markFailed($import, $exception?->getMessage() ?? 'Import failed.'));
        }
    }
}
