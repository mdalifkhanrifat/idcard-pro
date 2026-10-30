<?php

namespace App\Services\Webhooks;

use App\Data\Webhooks\WebhookFilter;
use App\Exceptions\BusinessRuleException;
use App\Models\Webhook;
use App\Repositories\Contracts\WebhookRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * An organization's webhooks: where it wants to be told, about what. Sending is WebhookDeliveryService's.
 */
class WebhookService
{
    public function __construct(private WebhookRepositoryInterface $webhooks) {}

    /**
     * @return LengthAwarePaginator<int, Webhook>
     */
    public function list(WebhookFilter $filter): LengthAwarePaginator
    {
        return $this->webhooks->list($filter);
    }

    /**
     * @param  array{name: string, url: string, events: array<int, string>, enabled?: bool}  $data
     */
    public function create(array $data): Webhook
    {
        if ($this->webhooks->count() >= (int) config('webhooks.max_per_organization')) {
            throw new BusinessRuleException(__('webhooks.too_many', ['max' => (int) config('webhooks.max_per_organization')]));
        }

        /** @var Webhook $webhook */
        $webhook = $this->webhooks->create([...$data, 'events' => array_values(array_unique($data['events'])), 'secret' => $this->newSecret()]);

        return $webhook;
    }

    /**
     * Whatever is not sent stays as it is.
     *
     * @param  array{name?: string, url?: string, events?: array<int, string>, enabled?: bool}  $data
     */
    public function update(Webhook $webhook, array $data): Webhook
    {
        if (isset($data['events'])) {
            $data['events'] = array_values(array_unique($data['events']));
        }
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

    private function newSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }
}
