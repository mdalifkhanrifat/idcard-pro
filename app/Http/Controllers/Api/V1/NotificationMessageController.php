<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\ListNotificationMessagesRequest;
use App\Http\Resources\NotificationMessageResource;
use App\Models\NotificationMessage;
use App\Services\Notifications\NotificationLogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class NotificationMessageController extends Controller
{
    public function __construct(private NotificationLogService $log) {}

    /**
     * The log of messages sent or to be sent. Filters: filter[search|status|channel|event|cardholder|card|
     * date_from|date_to] (a cardholder's include their family members'), sort=created_at|sent_at (- reverses);
     * meta lists the events, channels and statuses to filter by, with their names.
     */
    public function index(ListNotificationMessagesRequest $request): AnonymousResourceCollection
    {
        return NotificationMessageResource::collection($this->log->list($request->toFilter()))
            ->additional(['meta' => $this->log->options()]);
    }

    public function show(NotificationMessage $message): NotificationMessageResource
    {
        Gate::authorize('notifications.view');

        return new NotificationMessageResource($this->log->details($message));
    }
}
