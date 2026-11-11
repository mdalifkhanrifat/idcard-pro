<?php

namespace App\Http\Resources;

use App\Models\CardholderSync;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One run of the HR sync, as the sync answered it (data.id) and as cardholder.synced told it: who, when, how many of
 * each result. What was sent is never kept.
 *
 * @mixin CardholderSync
 */
class CardholderSyncResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'kind' => $this->kind->value,
            'account' => $this->user === null ? null : [
                'id' => $this->user->public_id,
                'name' => $this->user->name,
                'kind' => $this->user->kind,
                'deleted' => $this->user->trashed(),
            ],
            'total' => $this->total,
            'created' => $this->created_count,
            'updated' => $this->updated_count,
            'unchanged' => $this->unchanged_count,
            'failed' => $this->failed_count,
            'not_found' => $this->not_found_count,
            'started_at' => $this->started_at,
            // Null: the run broke off (what was saved until then stays)
            'finished_at' => $this->finished_at,
        ];
    }
}
