<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * What is known of a token, never the token.
 *
 * @mixin PersonalAccessToken
 */
class ApiTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'expires_at' => $this->expires_at,
            'expired' => $this->expires_at !== null && $this->expires_at->isPast(),
            'last_used_at' => $this->last_used_at,
            'created_at' => $this->created_at,
        ];
    }
}
