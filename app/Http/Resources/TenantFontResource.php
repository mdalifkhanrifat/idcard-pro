<?php

namespace App\Http\Resources;

use App\Models\TenantFont;
use App\Services\Fonts\TenantFontService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantFont
 */
class TenantFontResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'family' => $this->family,
            'weight' => $this->weight,
            'style' => $this->style,
            'format' => $this->format,
            'original_name' => $this->original_name,
            'size' => $this->size,
            'url' => app(TenantFontService::class)->url($this->resource),
            'created_at' => $this->created_at,
        ];
    }
}
