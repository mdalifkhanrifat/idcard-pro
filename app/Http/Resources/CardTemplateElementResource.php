<?php

namespace App\Http\Resources;

use App\Models\CardTemplateElement;
use App\Services\CardTemplates\CardTemplateImageService;
use App\Services\ImageLibrary\TenantImageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CardTemplateElement
 */
class CardTemplateElementResource extends JsonResource
{
    /**
     * Positions and sizes are millimetres from the top-left corner of the side.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'side' => $this->side,
            'type' => $this->type,
            'field' => $this->field,
            'label' => $this->label,
            'content' => $this->content,
            'qr_lines' => $this->qr_lines,
            'image' => $this->image_path === null ? null : basename($this->image_path),
            'library_image' => $this->libraryImage?->public_id,
            'image_url' => $this->libraryImage !== null
                ? app(TenantImageService::class)->url($this->libraryImage)
                : app(CardTemplateImageService::class)->url($this->template, $this->image_path),
            'x' => (float) $this->x,
            'y' => (float) $this->y,
            'width' => (float) $this->width,
            'height' => (float) $this->height,
            'z_index' => $this->z_index,
            'style' => (object) ($this->style ?? []),
        ];
    }
}
