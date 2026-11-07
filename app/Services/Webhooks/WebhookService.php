<?php

namespace App\Services\Webhooks;

use App\Data\Webhooks\WebhookFilter;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\Webhook;
use App\Repositories\Contracts\WebhookRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An organization's webhooks: where it wants to be told, about what. Sending is WebhookDeliveryService's.
 */
class WebhookService
{
    public function __construct(private WebhookRepositoryInterface $webhooks, private TenantContext $tenantContext) {}

    /**
     * @return LengthAwarePaginator<int, Webhook>
     */
    public function list(WebhookFilter $filter): LengthAwarePaginator
    {
        return $this->webhooks->list($filter);
    }

    /**
     * Failed deliveries in a row after which a webhook of this organization turns itself off.
     */
    public function disableAfter(): int
    {
        $tenant = $this->tenantContext->get();

        return ($tenant?->webhookSettings() ?? WebhookSettings::normalize([]))['disable_after'];
    }

    /**
     * @param  array{name: string, url: string, events: array<int, string>, enabled?: bool}  $data
     *
     * @throws ValidationException when it would hand over what the person may not see themselves
     */
    public function create(array $data, User $actor): Webhook
    {
        if ($this->webhooks->count() >= (int) config('webhooks.max_per_organization')) {
            throw new BusinessRuleException(__('webhooks.too_many', ['max' => (int) config('webhooks.max_per_organization')]));
        }
        $this->ensureWithinReach($actor, $data['events']);

        /** @var Webhook $webhook */
        $webhook = $this->webhooks->create([...$data, 'events' => array_values(array_unique($data['events'])), 'secret' => $this->newSecret()]);

        return $webhook;
    }

    /**
     * Whatever is not sent stays as it is.
     *
     * @param  array{name?: string, url?: string, events?: array<int, string>, enabled?: bool}  $data
     *
     * @throws ValidationException when it would hand over what the person may not see themselves: an event added, or
     *                             everything it sends to a new address
     */
    public function update(Webhook $webhook, array $data, User $actor): Webhook
    {
        if (isset($data['events'])) {
            $data['events'] = array_values(array_unique($data['events']));
        }
        $events = $data['events'] ?? $webhook->events;
        $moved = isset($data['url']) && $data['url'] !== $webhook->url;
        $this->ensureWithinReach($actor, $moved ? $events : array_values(array_diff($events, $webhook->events)));

        // Turned on again: a clean start, whatever had turned it off
        if (($data['enabled'] ?? false) && ! $webhook->enabled) {
            $data += ['failure_count' => 0, 'disabled_at' => null];
        }

        /** @var Webhook $updated */
        $updated = $this->webhooks->update($webhook, $data);

        return $updated;
    }

    /**
     * A new secret, for when the old one may have got out; the old one stops at once.
     */
    public function rotateSecret(Webhook $webhook): Webhook
    {
        $webhook->forceFill(['secret' => $this->newSecret(), 'secret_rotated_at' => now()])->save();

        return $webhook;
    }

    public function delete(Webhook $webhook): void
    {
        $this->webhooks->delete($webhook);
    }

    /**
     * A webhook hands data to an address, so it must not be a way round what a person may see.
     *
     * @param  array<int, string>  $events
     */
    private function ensureWithinReach(User $actor, array $events): void
    {
        $beyond = WebhookEvents::beyond($actor, $events);
        if ($beyond !== []) {
            throw ValidationException::withMessages(['events' => __('webhooks.beyond_reach', ['events' => implode(', ', array_map(WebhookEvents::name(...), $beyond))])]);
        }
    }

    private function newSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }
}
