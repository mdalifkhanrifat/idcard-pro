<?php

namespace App\Http\Resources;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookEvents;
use Illuminate\Http\Request;

/**
 * One delivery with what was sent. That holds personal data, so it is here only, never in a list, and only for someone
 * who may see that data themselves. It is the exact text sent: decoded, an empty object would come back a list.
 *
 * @mixin WebhookDelivery
 */
class WebhookDeliveryDetailResource extends WebhookDeliveryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $readable = WebhookEvents::readable($request->user(), $this->event);

        return [
            ...parent::toArray($request),
            'payload' => $readable ? $this->payload : null,
            'payload_hidden' => ! $readable,
        ];
    }
}
