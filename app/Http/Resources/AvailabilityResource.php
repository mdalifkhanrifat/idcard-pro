<?php

namespace App\Http\Resources;

use App\Data\Appointments\Availability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a place has free for a kind of visit: how it is booked, why nothing can be (named in the reader's
 * language), the kind of visit and the days — times with the organization's offset.
 *
 * @property Availability $resource
 */
class AvailabilityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $availability = $this->resource;

        return [
            'scheduling' => $availability->scheduling,
            'unavailable' => $availability->unavailable === null ? null : [
                'key' => $availability->unavailable,
                'message' => __("appointments.availability.unavailable.{$availability->unavailable}"),
            ],
            'service' => $availability->service,
            'from' => $availability->from,
            'to' => $availability->to,
            'days' => $availability->days,
        ];
    }
}
