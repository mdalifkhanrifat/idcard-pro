<?php

namespace App\Http\Resources;

use App\Models\Cardholder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/**
 * @mixin Activity
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'log_name' => $this->log_name,
            'event' => $this->event,
            'description' => $this->description,
            'subject_type' => class_basename((string) $this->subject_type),
            'causer' => $this->causer(),
            // Field changes of a record, else what an event recorded (an override's reason, a download's report...)
            'changes' => $this->recorded(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Who acted: a user (kind "user"), or a cardholder acting for themself on the public booking link (kind
     * "cardholder", with their employee number instead of an email); null for the system.
     *
     * @return array{kind: string, name: string|null, email: string|null, employee_no?: string|null}|null
     */
    private function causer(): ?array
    {
        $causer = $this->causer;

        return match (true) {
            $causer === null => null,
            $causer instanceof Cardholder => ['kind' => 'cardholder', 'name' => $causer->full_name, 'email' => null, 'employee_no' => $causer->employee_no],
            default => ['kind' => 'user', 'name' => $causer->getAttribute('name'), 'email' => $causer->getAttribute('email')],
        };
    }

    /**
     * Events logged by hand store an empty list of field changes (not null), so emptiness decides.
     *
     * @return array<string, mixed>|null
     */
    private function recorded(): ?array
    {
        $changes = $this->attribute_changes?->toArray() ?? [];
        if ($changes !== []) {
            return $changes;
        }
        $properties = $this->properties?->toArray() ?? [];

        return $properties !== [] ? $properties : null;
    }
}
