<?php

namespace App\Http\Resources;

use App\Models\CardholderImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CardholderImport
 */
class CardholderImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'original_name' => $this->original_name,
            'status' => $this->status,
            'options' => $this->options,
            'summary' => $this->summary,
            'total_rows' => $this->total_rows,
            'valid_rows' => $this->valid_rows,
            'created_count' => $this->created_count,
            'updated_count' => $this->updated_count,
            'failed_count' => $this->failed_count,
            'errors' => $this->errors ?? [],
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->public_id, 'name' => $this->user->name] : null),
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'created_at' => $this->created_at,
        ];
    }
}
