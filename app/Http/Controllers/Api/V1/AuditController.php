<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ListActivitiesRequest;
use App\Http\Requests\Audit\ListLoginEventsRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\LoginEventResource;
use App\Services\Audit\AuditService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditController extends Controller
{
    public function __construct(private AuditService $audit) {}

    /**
     * Data change history. Filters: filter[log_name|event|causer|date_from|date_to]; meta.log_names lists
     * the kinds of record that have changes, so new modules appear in the filter by themselves.
     */
    public function activities(ListActivitiesRequest $request): AnonymousResourceCollection
    {
        return ActivityResource::collection($this->audit->activities($request->toFilter()))
            ->additional(['meta' => ['log_names' => $this->audit->logNames(), 'events' => $this->audit->events()]]);
    }

    /**
     * Sign-in history. Filters: filter[event|identifier|ip_address|date_from|date_to].
     */
    public function logins(ListLoginEventsRequest $request): AnonymousResourceCollection
    {
        return LoginEventResource::collection($this->audit->logins($request->toFilter()));
    }
}
