<?php

namespace App\Http\Resources;

use App\Models\LoginEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoginEvent
 */
class LoginEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'identifier' => $this->identifier,
            'channel' => ['key' => $this->channel->value, 'name' => $this->channel->label()],
            'user' => $this->user?->only(['name', 'email']),
            // A cardholder who proved who they are on the public booking link
            'cardholder' => $this->cardholder === null ? null : ['name' => $this->cardholder->full_name, 'employee_no' => $this->cardholder->employee_no],
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at,
        ];
    }
}
