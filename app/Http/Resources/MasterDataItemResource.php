<?php

namespace App\Http\Resources;

use App\Models\MasterDataItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MasterDataItem
 */
class MasterDataItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'type' => $this->type,
            'name' => $this->name,
            'name_local' => $this->name_local,
            'code' => $this->code,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'id' => $this->parent->public_id,
                'name' => $this->parent->name,
            ] : null),
            'children_count' => $this->whenCounted('children'),
            'updated_at' => $this->updated_at,
        ];
    }
}
