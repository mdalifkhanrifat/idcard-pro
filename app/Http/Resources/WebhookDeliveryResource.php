<?php

namespace App\Http\Resources;

use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDeliveryService;
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
            'resend_of' => $this->resendOf?->public_id,
            'resent_as' => $this->resend?->public_id,
            'actions' => ['resend' => $this->resendAction($request)],
        ];
    }

    /**
     * Whether to offer sending it again, by the rule the resend itself follows. Not offered at all where it can never
     * apply; offered and closed, with the reason, where the person could do something about it.
     *
     * @return array{allowed: bool, reason: string|null}|null
     */
    private function resendAction(Request $request): ?array
    {
        $problem = app(WebhookDeliveryService::class)->resendProblem($this->resource, $request->user());
        if (in_array($problem, ['test', 'not_failed'], true)) {
            return null;
        }

        return ['allowed' => $problem === null, 'reason' => $problem === null ? null : (string) __("webhooks.resend.{$problem}")];
    }
}
