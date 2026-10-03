<?php

namespace App\Http\Resources;

use App\Models\Signatory;
use App\Services\Signatories\SignatoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Signatory
 */
class SignatoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'name_local' => $this->name_local,
            'designation' => $this->designation,
            'designation_local' => $this->designation_local,
            'authority_type' => $this->whenLoaded('authorityType', fn () => $this->authorityType ? [
                'id' => $this->authorityType->public_id,
                'name' => $this->authorityType->name,
            ] : null),
            'signature_url' => app(SignatoryService::class)->signatureUrl($this->resource),
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'is_active' => $this->is_active,
            'updated_at' => $this->updated_at,
        ];
    }
}
