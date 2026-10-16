<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use App\Services\Appointments\AppointmentSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An appointment as the API answers it: its number, status and source named in the reader's language, the
 * place, the kind of visit, the person, the day and — when times are booked — its times with the
 * organization's offset.
 *
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenant = app(TenantContext::class)->get();
        $timezone = $tenant->timezone ?: (string) config('app.timezone');
        $kind = $tenant->appointmentSettings()['services'][$this->service] ?? null;

        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'status' => ['key' => $this->status->value, 'name' => __("appointments.statuses.{$this->status->value}")],
            'source' => ['key' => $this->source->value, 'name' => __("appointments.sources.{$this->source->value}")],
            'location' => $this->whenLoaded('location', fn () => ['id' => $this->location->public_id, 'name' => $this->location->name, 'name_local' => $this->location->name_local]),
            // A kind of visit removed since keeps its key as its name
            'service' => ['key' => $this->service, 'name' => $kind === null ? $this->service : AppointmentSettings::serviceName($this->service, $kind)],
            'cardholder' => $this->whenLoaded('cardholder', fn () => ['id' => $this->cardholder->public_id, 'name' => $this->cardholder->full_name, 'name_local' => $this->cardholder->full_name_local, 'employee_no' => $this->cardholder->employee_no]),
            'family_member' => $this->whenLoaded('familyMember', fn () => $this->familyMember === null ? null : ['id' => $this->familyMember->public_id, 'name' => $this->familyMember->name, 'relation' => $this->familyMember->relation->value]),
            'date' => $this->date->toDateString(),
            'starts_at' => $this->starts_at?->setTimezone($timezone)->toIso8601String(),
            'ends_at' => $this->ends_at?->setTimezone($timezone)->toIso8601String(),
            'note' => $this->note,
            'cancel_reason' => $this->cancel_reason,
            'booked_by' => $this->whenLoaded('bookedBy', fn () => $this->bookedBy === null ? null : ['id' => $this->bookedBy->public_id, 'name' => $this->bookedBy->name]),
            'status_changed_at' => $this->status_changed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
