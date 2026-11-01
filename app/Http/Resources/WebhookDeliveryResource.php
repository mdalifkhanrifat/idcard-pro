<?php

namespace App\Http\Resources;

use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookEvents;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WebhookDelivery
 */
class WebhookDeliveryResource extends JsonResource
{
    /**
     * Every status, named, for choosing one in the log.
     *
     * @return array<int, array{key: string, name: string}>
     */
    public static function statuses(): array
    {
        return array_map(fn (WebhookDeliveryStatus $status): array => [
            'key' => $status->value,
            'name' => (string) __("webhooks.statuses.{$status->value}"),
        ], WebhookDeliveryStatus::cases());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'event' => ['key' => $this->event, 'name' => WebhookEvents::name($this->event)],
            'status' => ['key' => $this->status->value, 'name' => (string) __("webhooks.statuses.{$this->status->value}")],
            'attempts' => $this->attempts,
            'response_code' => $this->response_code,
            'failure' => $this->failure === null ? null : ['key' => $this->failure->value, 'name' => $this->failure->label()],
            'delivered_at' => $this->delivered_at,
            'created_at' => $this->created_at,
        ];
    }
}
