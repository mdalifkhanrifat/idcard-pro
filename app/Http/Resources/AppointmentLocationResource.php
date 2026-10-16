<?php

namespace App\Http\Resources;

use App\Models\AppointmentLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AppointmentLocation
 */
class AppointmentLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'name_local' => $this->name_local,
            'address' => $this->address,
            'slot_capacity' => $this->slot_capacity,
            'day_capacity' => $this->day_capacity,
            // null: every kind of visit
            'services' => $this->services,
            'hours' => $this->hours,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
