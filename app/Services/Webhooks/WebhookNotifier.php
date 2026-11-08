<?php

namespace App\Services\Webhooks;

use App\Models\Appointment;
use App\Models\Card;
use App\Models\Cardholder;
use App\Models\CardholderImport;
use App\Models\CardholderSync;
use App\Models\Tenant;
use App\Models\Webhook;
use App\Repositories\Contracts\WebhookRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Closure;
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
    /** The batch event of the file being worked through, if any. */
    private ?string $batch = null;

    public function __construct(
        private WebhookRepositoryInterface $webhooks,
        private WebhookDeliveryService $deliveries,
        private WebhookPayloads $payloads,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @param  string  $event  a config('webhooks.events') key
     * @param  array<string, mixed>  $change  what changed, when the record alone does not say (a family member, a
     *                                        file): sent as data.change, ids and words only, never a file or a link
     */
    public function tell(string $event, Appointment|Card|Cardholder $subject, array $change = []): void
    {
        $tenant = $this->tenantContext->get();
        if ($tenant !== null) {
            // Asked now: it is the moment of the change that decides, not the moment of saving
            $folded = $this->batch !== null && in_array($event, (array) ((array) config('webhooks.batches'))[$this->batch], true) ? $this->batch : null;
            DB::afterCommit(fn () => $this->send($event, fn (): array => [...$this->payloads->of($subject, $tenant), ...($change === [] ? [] : ['change' => $change])], $tenant, $folded));
        }
    }

    /**
     * Work through a batch (a file, a request of an HR sync): webhooks that chose the batch event hear nothing of its
     * records, and are told of the whole with tellImported() or tellSynced() instead.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function inBatch(string $event, Closure $work): mixed
    {
        $this->batch = $event;
        try {
            return $work();
        } finally {
            $this->batch = null;
        }
    }

    /**
     * What a file did, once it ended, however it ended.
     *
     * @param  array{created: array<int, string>, updated: array<int, string>}|null  $changed  public ids; null when not known
     */
    public function tellImported(string $event, CardholderImport $import, ?array $changed): void
    {
        $tenant = $this->tenantContext->get();
        if ($tenant !== null) {
            $this->send($event, fn (): array => $this->payloads->ofImport($import, $changed), $tenant, null);
        }
    }

    /**
     * What one request of an HR sync did (the batch event cardholder.synced), however it ended.
     *
     * @param  array{created: array<int, string>, updated: array<int, string>}  $changed  public ids
     */
    public function tellSynced(CardholderSync $sync, array $changed): void
    {
        $tenant = $this->tenantContext->get();
        if ($tenant !== null) {
            $this->send('cardholder.synced', fn (): array => $this->payloads->ofSync($sync, $changed), $tenant, null);
        }
    }

    /**
     * @param  Closure(): array<string, mixed>  $describe  only when some webhook is to be told
     * @param  string|null  $folded  the batch event that stands in for this one, for the webhooks that chose it
     */
    private function send(string $event, Closure $describe, Tenant $tenant, ?string $folded): void
    {
        try {
            $this->tenantContext->run($tenant, function () use ($event, $describe, $folded): void {
                $webhooks = $this->webhooks->listeningTo($event)
                    ->reject(fn (Webhook $webhook): bool => $folded !== null && in_array($folded, $webhook->events, true));
                if ($webhooks->isEmpty()) {
                    return;
                }
                $data = $describe();
                $webhooks->each(fn (Webhook $webhook) => $this->deliveries->queue($webhook, $event, $data));
            });
        } catch (Throwable $failure) {
            report($failure);
        }
    }
}
