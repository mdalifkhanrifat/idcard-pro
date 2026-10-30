<?php

namespace App\Services\Webhooks;

use App\Models\Appointment;
use App\Models\Card;
use App\Models\Cardholder;
use App\Models\Tenant;
use App\Repositories\Contracts\WebhookRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tells the organization's webhooks that something happened, called by the services every way in goes through
 * (the pages, an import, an API token, the public link). It waits until the change is saved, so nothing is said about
 * what was undone, and describes it only when some webhook chose that event. A webhook that cannot be told never
 * undoes the change: it is reported and the change stands.
 */
class WebhookNotifier
{
    public function __construct(
        private WebhookRepositoryInterface $webhooks,
        private WebhookDeliveryService $deliveries,
        private WebhookPayloads $payloads,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @param  string  $event  a config('webhooks.events') key
     */
    public function tell(string $event, Appointment|Card|Cardholder $subject): void
    {
        $tenant = $this->tenantContext->get();
        if ($tenant !== null) {
            DB::afterCommit(fn () => $this->send($event, $subject, $tenant));
        }
    }

    private function send(string $event, Appointment|Card|Cardholder $subject, Tenant $tenant): void
    {
        try {
            $this->tenantContext->run($tenant, function () use ($event, $subject, $tenant): void {
                $webhooks = $this->webhooks->listeningTo($event);
                if ($webhooks->isEmpty()) {
                    return;
                }
                $data = $this->payloads->of($subject, $tenant);
                $webhooks->each(fn ($webhook) => $this->deliveries->queue($webhook, $event, $data));
            });
        } catch (Throwable $failure) {
            report($failure);
        }
    }
}
