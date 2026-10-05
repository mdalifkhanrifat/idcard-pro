<?php

namespace App\Http\Resources;

use App\Models\TenantImage;
use App\Services\ImageLibrary\TenantImageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantImage
 */
class TenantImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'width' => $this->width,
            'height' => $this->height,
            'size' => $this->size,
            'mime_type' => $this->mime_type,
            'url' => app(TenantImageService::class)->url($this->resource),
            // Only selected by the library listing
            'templates_count' => $this->when(array_key_exists('templates_count', $this->resource->getAttributes()), fn () => $this->templates_count),
            'created_at' => $this->created_at,
        ];
    }
}
