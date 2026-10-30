<?php

namespace App\Services\Webhooks;

use App\Data\Webhooks\WebhookResult;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookFailure;
use App\Exceptions\WebhookNotDelivered;
use App\Jobs\SendWebhook;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Repositories\Contracts\WebhookDeliveryRepositoryInterface;
use App\Repositories\Contracts\WebhookRepositoryInterface;
use App\Services\Notifications\NotificationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Telling an address what happened: a logged delivery, handed to the queue and tried until it gets through or runs
 * out of attempts. An address that keeps failing is turned off, and the people who look after it are told; one
 * delivery is one failure however many attempts it took, and any success starts the count again.
 */
class WebhookDeliveryService
{
    /** The event of a delivery sent by hand to check an address; no webhook chooses it. */
    public const TEST_EVENT = 'webhook.test';

    public function __construct(
        private WebhookDeliveryRepositoryInterface $deliveries,
        private WebhookRepositoryInterface $webhooks,
        private WebhookSender $sender,
        private NotificationService $notifications,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Log a delivery and hand it to the queue, once whatever caused it is saved.
     *
     * @param  array<string, mixed>  $data  what happened, as the API describes it
     */
    public function queue(Webhook $webhook, string $event, array $data): WebhookDelivery
    {
        $delivery = $this->log($webhook, $event, $data);
        SendWebhook::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }

    /**
     * One attempt now, whether or not the webhook is on (before turning it on again, too), answered at once. It
     * does not count towards turning the webhook off. Noted in the audit log, since it sends something out.
     */
    public function test(Webhook $webhook, User $sender): WebhookDelivery
    {
        $delivery = $this->log($webhook, self::TEST_EVENT, ['test' => true]);
        $delivery->attempts = 1;
        $this->settle($delivery, $this->sender->send($webhook, $delivery));
        if ($delivery->status !== WebhookDeliveryStatus::Delivered) {
            $this->deliveries->update($delivery, ['status' => WebhookDeliveryStatus::Failed]);
        }

        activity('webhooks')
            ->performedOn($webhook)
            ->causedBy($sender)
            ->event('tested')
            ->withProperties(['delivery' => $delivery->public_id, 'status' => $delivery->status->value, 'response_code' => $delivery->response_code])
            ->log('tested');

        return $delivery;
    }

    /**
     * From the queue. Only while still queued, so a delivery that got through is never sent twice; a webhook turned
     * off or deleted meanwhile is not told.
     *
     * @throws WebhookNotDelivered when the attempt failed (the queue tries again)
     */
    public function deliver(int $deliveryId): void
    {
        $delivery = $this->deliveries->findForDelivery($deliveryId);
        if ($delivery === null || $delivery->status !== WebhookDeliveryStatus::Queued) {
            return;
        }

        $this->tenantContext->run($delivery->tenant, function () use ($delivery): void {
            $webhook = $delivery->load('webhook')->webhook;
            if (! $webhook->enabled || $webhook->trashed()) {
                $this->deliveries->update($delivery, ['status' => WebhookDeliveryStatus::Failed, 'failure' => WebhookFailure::Disabled]);

                return;
            }

            $delivery->attempts++;
            $result = $this->sender->send($webhook, $delivery);
            $this->settle($delivery, $result);
            if ($result->failure !== null) {
                throw new WebhookNotDelivered($delivery->public_id, $result->failure);
            }
            if ($webhook->failure_count > 0) {
                $this->webhooks->update($webhook, ['failure_count' => 0]);
            }
        });
    }

    /**
     * Every attempt failed: the delivery stays in the log as failed, and the webhook has one failure more.
     */
    public function fail(int $deliveryId): void
    {
        $delivery = $this->deliveries->findForDelivery($deliveryId);
        if ($delivery === null || $delivery->status !== WebhookDeliveryStatus::Queued) {
            return;
        }

        $this->tenantContext->run($delivery->tenant, function () use ($delivery): void {
            $this->deliveries->update($delivery, ['status' => WebhookDeliveryStatus::Failed]);

            $turnedOff = DB::transaction(function () use ($delivery): ?Webhook {
                $webhook = $this->webhooks->lockForFailure($delivery->webhook_id);
                $webhook->failure_count++;
                $reached = $webhook->enabled && $webhook->failure_count >= (int) config('webhooks.disable_after');
                if ($reached) {
                    $webhook->enabled = false;
                    $webhook->disabled_at = now();
                }
                // The audit entry below says this for the organization; a count needs no entry of its own
                $webhook->saveQuietly();

                return $reached ? $webhook : null;
            });

            if ($turnedOff !== null) {
                $this->noteTurnedOff($turnedOff, $delivery->tenant);
            }
        });
    }

    /**
     * By the system, about the organization itself (its audit page shows such entries).
     */
    private function noteTurnedOff(Webhook $webhook, Tenant $tenant): void
    {
        activity('webhooks')
            ->performedOn($tenant)
            ->causedByAnonymous()
            ->event('disabled')
            ->withProperties([
                'attributes' => ['name' => $webhook->name, 'enabled' => false, 'failure_count' => $webhook->failure_count],
                'old' => ['enabled' => true],
            ])
            ->log('disabled');

        $this->notifications->sendWebhookDisabled($webhook);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function log(Webhook $webhook, string $event, array $data): WebhookDelivery
    {
        $tenant = $this->tenantContext->get();
        assert($tenant instanceof Tenant);
        $id = (new WebhookDelivery)->newUniqueId();

        return $this->deliveries->create([
            'public_id' => $id,
            'webhook_id' => $webhook->id,
            'event' => $event,
            'payload' => ['id' => $id, 'event' => $event, 'occurred_at' => now()->utc()->toIso8601ZuluString(), 'tenant' => $tenant->slug, 'data' => $data],
        ]);
    }

    private function settle(WebhookDelivery $delivery, WebhookResult $result): void
    {
        $this->deliveries->update($delivery, [
            'attempts' => $delivery->attempts,
            'response_code' => $result->code,
            'failure' => $result->failure,
            ...($result->delivered() ? ['status' => WebhookDeliveryStatus::Delivered, 'delivered_at' => now()] : []),
        ]);
    }
}
