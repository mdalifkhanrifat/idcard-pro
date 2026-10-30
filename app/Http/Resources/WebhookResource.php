<?php

namespace App\Http\Resources;

use App\Models\Webhook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Webhook
 */
class WebhookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'url' => $this->url,
            'events' => $this->events,
            'enabled' => $this->enabled,
            'secret_rotated_at' => $this->secret_rotated_at,
            'created_at' => $this->created_at,
        ];
    }
}
