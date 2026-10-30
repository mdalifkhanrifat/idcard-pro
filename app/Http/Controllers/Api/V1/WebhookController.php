<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\ListWebhooksRequest;
use App\Http\Requests\Webhooks\SaveWebhookRequest;
use App\Http\Resources\WebhookResource;
use App\Models\Webhook;
use App\Services\Webhooks\WebhookEvents;
use App\Services\Webhooks\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class WebhookController extends Controller
{
    public function __construct(private WebhookService $webhooks) {}

    /**
     * Filters: filter[search|enabled]. Sorts: name, created_at (prefix "-" for descending). meta.events: what can be
     * chosen, grouped and named.
     */
    public function index(ListWebhooksRequest $request): AnonymousResourceCollection
    {
        return WebhookResource::collection($this->webhooks->list($request->toFilter()))
            ->additional(['meta' => ['events' => WebhookEvents::options()]]);
    }

    /**
     * The signing secret is in this answer and in a rotation's only: it cannot be read back.
     */
    public function store(SaveWebhookRequest $request): JsonResponse
    {
        return $this->withSecret($this->webhooks->create($request->validated()), 201);
    }

    public function show(Webhook $webhook): WebhookResource
    {
        Gate::authorize('integrations.manage');

        return new WebhookResource($webhook);
    }

    public function update(SaveWebhookRequest $request, Webhook $webhook): WebhookResource
    {
        return new WebhookResource($this->webhooks->update($webhook, $request->validated()));
    }

    public function rotateSecret(Webhook $webhook): JsonResponse
    {
        Gate::authorize('integrations.manage');

        return $this->withSecret($this->webhooks->rotateSecret($webhook), 200);
    }

    public function destroy(Webhook $webhook): JsonResponse
    {
        Gate::authorize('integrations.manage');

        $this->webhooks->delete($webhook);

        return response()->json(status: 204);
    }

    private function withSecret(Webhook $webhook, int $status): JsonResponse
    {
        return response()
            ->json(['data' => [...(new WebhookResource($webhook))->resolve(), 'secret' => $webhook->secret]], $status)
            ->header('Cache-Control', 'no-store');
    }
}
