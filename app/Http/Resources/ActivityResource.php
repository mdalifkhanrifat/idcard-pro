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
            'changes' => $this->attribute_changes ?? $this->properties,
            'created_at' => $this->created_at,
        ];
    }
}
