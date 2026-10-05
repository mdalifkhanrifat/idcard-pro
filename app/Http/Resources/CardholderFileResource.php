<?php

namespace App\Http\Resources;

use App\Models\CardholderFile;
use App\Services\Cardholders\CardholderFileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CardholderFile
 */
class CardholderFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'collection' => $this->collection,
            'family_member' => $this->whenLoaded('familyMember', fn () => $this->familyMember ? [
                'id' => $this->familyMember->public_id,
                'name' => $this->familyMember->name,
            ] : null),
            'title' => $this->title,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'is_current' => $this->replaced_at === null,
            'url' => app(CardholderFileService::class)->temporaryUrl($this->resource),
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader ? [
                'id' => $this->uploader->public_id,
                'name' => $this->uploader->name,
            ] : null),
            'replaced_at' => $this->replaced_at,
            'created_at' => $this->created_at,
        ];
    }
}
