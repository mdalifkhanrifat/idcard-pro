<?php

namespace App\Http\Resources;

use App\Models\AppointmentClosure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AppointmentClosure
 */
class AppointmentClosureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            // null: every place
            'location' => $this->location === null ? null : [
                'id' => $this->location->public_id,
                'name' => $this->location->name,
                'name_local' => $this->location->name_local,
            ],
            'date_from' => $this->date_from->toDateString(),
            'date_to' => $this->date_to->toDateString(),
            'yearly' => $this->yearly,
            'reason' => $this->reason,
            'reason_local' => $this->reason_local,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
