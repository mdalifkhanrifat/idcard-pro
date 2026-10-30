<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin User
 */
class IntegrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'description' => $this->description,
            'role' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->first()),
            'status' => $this->status,
            'allowed_ips' => $this->allowed_ips ?? [],
            'tokens_count' => $this->whenCounted('tokens'),
            'last_used_at' => $this->whenAggregated('tokens', 'last_used_at', 'max', fn (?string $moment) => $moment === null ? null : Carbon::parse($moment)),
            'created_at' => $this->created_at,
        ];
    }
}
