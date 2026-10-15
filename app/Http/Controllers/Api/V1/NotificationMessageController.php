<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\ListNotificationMessagesRequest;
use App\Http\Requests\Notifications\SendTestNotificationRequest;
use App\Http\Resources\NotificationMessageResource;
use App\Models\NotificationMessage;
use App\Services\Notifications\NotificationLogService;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /**
     * Send a failed or skipped message again: a new message (201) to the person's address on record now; the old
     * one stays, linked (resent_as). 422 with `errors.message` when it cannot go.
     */
    public function resend(Request $request, NotificationMessage $message, NotificationService $notifications): JsonResponse
    {
        Gate::authorize('notifications.send');

        $resent = $notifications->resend($message, $request->user());

        return (new NotificationMessageResource($this->log->details($resent)))->response()->setStatusCode(201);
    }

    /**
     * Send a test message on a channel (`channel`) to `address` (default: the sender's own): 201 with the new
     * message, whose status can then be followed with show().
     */
    public function sendTest(SendTestNotificationRequest $request, NotificationService $notifications): JsonResponse
    {
        $message = $notifications->sendTest($request->user(), (string) $request->validated('channel'), (string) $request->validated('address'));

        return (new NotificationMessageResource($this->log->details($message)))->response()->setStatusCode(201);
    }
}
