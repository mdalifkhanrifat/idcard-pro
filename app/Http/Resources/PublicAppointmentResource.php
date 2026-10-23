<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use App\Services\Appointments\AppointmentSettings;
use App\Services\Appointments\PublicBooking\PublicAppointmentService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An appointment as the person sees their own on the public link (5.6): its number, status, kind, place, for whom,
 * when (with the organization's offset) and their note — nothing of the staff's (who booked or took a step) — and
 * what they may do with it now (`actions`: cancel, move).
 *
 * @mixin Appointment
 */
class PublicAppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenant = app(TenantContext::class)->get();
        $timezone = $tenant->timezone ?: (string) config('app.timezone');

        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'status' => ['key' => $this->status->value, 'name' => __("appointments.statuses.{$this->status->value}"), 'open' => $this->status->isOpen()],
            'service' => ['key' => $this->service, 'name' => AppointmentSettings::serviceNames($tenant)[$this->service] ?? $this->service],
            'location' => $this->whenLoaded('location', fn () => ['id' => $this->location->public_id, 'name' => $this->location->name, 'name_local' => $this->location->name_local, 'address' => $this->location->address]),
            'family_member' => $this->whenLoaded('familyMember', fn () => $this->familyMember === null ? null : ['id' => $this->familyMember->public_id, 'name' => $this->familyMember->name, 'relation' => $this->familyMember->relation->value]),
            'date' => $this->date->toDateString(),
            'starts_at' => $this->starts_at?->setTimezone($timezone)->toIso8601String(),
            'ends_at' => $this->ends_at?->setTimezone($timezone)->toIso8601String(),
            'note' => $this->note,
            'cancel_reason' => $this->cancel_reason,
            'actions' => app(PublicAppointmentService::class)->actions($this->resource),
        ];
    }
}
