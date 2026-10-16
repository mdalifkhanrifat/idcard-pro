<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use App\Services\Appointments\AppointmentSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An organization's appointment settings (always complete) and what they can be set to, named in the reader's
 * language; with how many places it has and has on (read only — not part of the settings, not taken back).
 *
 * @mixin Tenant
 */
class AppointmentSettingsResource extends JsonResource
{
    /**
     * @param  array{total: int, active: int}  $places
     */
    public function __construct(Tenant $tenant, private array $places)
    {
        parent::__construct($tenant);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'appointments' => $this->resource->appointmentSettings(),
            'options' => AppointmentSettings::options(),
            'places' => $this->places,
        ];
    }
}
