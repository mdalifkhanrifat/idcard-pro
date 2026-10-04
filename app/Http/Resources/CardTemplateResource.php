<?php

namespace App\Http\Resources;

use App\Models\CardTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CardTemplate
 */
class CardTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lookup = fn (string $relation) => $this->whenLoaded($relation, fn () => $this->{$relation} ? [
            'id' => $this->{$relation}->public_id,
            'name' => $this->{$relation}->name,
        ] : null);

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'code' => $this->code,
            'card_type' => $lookup('cardType'),
            'audience' => $this->audience,
            'orientation' => $this->orientation,
            'width' => (float) $this->width,
            'height' => (float) $this->height,
            'size_unit' => $this->size_unit,
            'authority_type' => $lookup('authorityType'),
            'validity_months' => $this->validity_months,
            'is_double_sided' => $this->is_double_sided,
            'is_active' => $this->is_active,
            'version' => $this->version,
            'elements' => CardTemplateElementResource::collection($this->whenLoaded('elements')),
            'updated_at' => $this->updated_at,
        ];
    }
}
