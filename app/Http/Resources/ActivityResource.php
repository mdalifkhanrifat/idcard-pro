<?php

namespace App\Http\Resources;

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
            'causer' => $this->causer?->only(['name', 'email']),
            // Field changes of a record, else what an event recorded (an override's reason, a download's report...)
            'changes' => $this->recorded(),
            'created_at' => $this->created_at,
        ];
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
