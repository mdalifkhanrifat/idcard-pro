<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use App\Services\Cards\CardNumberFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'legal_name' => $this->legal_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'country_code' => $this->country_code,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'primary_color' => $this->primary_color,
            'logo_url' => $this->logo_path ? url('storage/'.$this->logo_path) : null,
            'card_number_format' => $this->settings['card_number_format'] ?? CardNumberFormat::DEFAULT,
            'status' => $this->status,
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at,
        ];
    }
}
