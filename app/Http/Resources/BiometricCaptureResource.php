<?php

namespace App\Http\Resources;

use App\Models\BiometricCapture;
use App\Services\Biometrics\BiometricService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A capture as connected systems and the enrollment screen see it: what, how well, by whom and when.
 * The image only through a short-lived signed link; template bytes are never returned.
 *
 * @mixin BiometricCapture
 */
class BiometricCaptureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'modality' => $this->modality,
            'position' => $this->position,
            // Standard position code (ISO/IEC 19794) for exchange with other systems
            'position_code' => config("biometrics.modalities.{$this->modality}.positions.{$this->position}"),
            'status' => $this->status,
            'unavailable_reason' => $this->unavailable_reason,
            'note' => $this->note,
            'driver' => $this->driver,
            'device' => $this->device,
            'image_format' => $this->image_format,
            'image_width' => $this->image_width,
            'image_height' => $this->image_height,
            'image_dpi' => $this->image_dpi,
            'image_url' => app(BiometricService::class)->imageUrl($this->resource),
            'has_template' => $this->template_path !== null,
            'template_format' => $this->template_format,
            'quality' => $this->quality,
            'low_quality' => $this->low_quality,
            'attempts' => $this->attempts,
            'captured_by' => $this->whenLoaded('capturer', fn () => $this->capturer ? [
                'id' => $this->capturer->public_id,
                'name' => $this->capturer->name,
            ] : null),
            'captured_at' => $this->captured_at,
        ];
    }
}
