<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use App\Services\Appointments\AppointmentSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An organization's appointment settings (always complete) and what they can be set to, named in the reader's
 * language.
 *
 * @mixin Tenant
 */
class AppointmentSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'appointments' => $this->resource->appointmentSettings(),
            'options' => AppointmentSettings::options(),
        ];
    }
}
