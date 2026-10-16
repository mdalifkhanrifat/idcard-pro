<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appointments\AvailabilityRequest;
use App\Http\Resources\AvailabilityResource;
use App\Models\AppointmentLocation;
use App\Services\Appointments\AvailabilityService;

/**
 * What a place has free for a kind of visit, day by day: for staff, and as the public link would show it.
 * Seen by those who see appointments or may change the settings (the settings page previews a place with it).
 */
class AppointmentAvailabilityController extends Controller
{
    public function __construct(private AvailabilityService $availability) {}

    public function show(AvailabilityRequest $request, AppointmentLocation $appointmentLocation): AvailabilityResource
    {
        [$from, $to] = $request->days();

        return new AvailabilityResource($this->availability->for($appointmentLocation, (string) $request->validated('service'), $from, $to, $request->audience(), preview: $request->boolean('preview')));
    }
}
