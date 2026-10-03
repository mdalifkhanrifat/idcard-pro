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
     * Data change history. Filters: filter[log_name|event|causer|date_from|date_to].
     */
    public function activities(ListActivitiesRequest $request): AnonymousResourceCollection
    {
        return ActivityResource::collection($this->audit->activities($request->toFilter()));
    }

    /**
     * Sign-in history. Filters: filter[event|identifier|ip_address|date_from|date_to].
     */
    public function logins(ListLoginEventsRequest $request): AnonymousResourceCollection
    {
        return LoginEventResource::collection($this->audit->logins($request->toFilter()));
    }
}
