<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\ListWebhookDeliveriesRequest;
use App\Http\Resources\WebhookDeliveryDetailResource;
use App\Http\Resources\WebhookDeliveryResource;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDeliveryService;
use App\Services\Webhooks\WebhookEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class WebhookDeliveryController extends Controller
{
    public function __construct(private WebhookDeliveryService $deliveries) {}

    /**
     * Newest first. Filters: filter[status|event]. meta: what can be chosen in them, named.
     */
    public function index(ListWebhookDeliveriesRequest $request, Webhook $webhook): AnonymousResourceCollection
    {
        return WebhookDeliveryResource::collection($this->deliveries->list($webhook, $request->toFilter()))
            ->additional(['meta' => ['statuses' => WebhookDeliveryResource::statuses(), 'events' => WebhookEvents::logged()]]);
    }

    public function show(Webhook $webhook, WebhookDelivery $delivery): WebhookDeliveryDetailResource
    {
        Gate::authorize('integrations.manage');

        return new WebhookDeliveryDetailResource($this->deliveries->details($delivery));
    }

    /**
     * Send a failed delivery again: a new delivery (201), the old one stays, linked (resent_as). 422 with
     * `errors.delivery` when it cannot go.
     */
    public function resend(Request $request, Webhook $webhook, WebhookDelivery $delivery): JsonResponse
    {
        Gate::authorize('integrations.manage');

        return (new WebhookDeliveryDetailResource($this->deliveries->resend($delivery, $request->user())))->response()->setStatusCode(201);
    }
}
