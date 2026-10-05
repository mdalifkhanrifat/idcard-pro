<?php

namespace App\Http\Resources;

use App\Models\CardPrint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CardPrint
 */
class CardPrintResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'card' => $this->whenLoaded('card', fn () => ['id' => $this->card->public_id, 'number' => $this->card->number]),
            'batch' => $this->batch,
            'method' => $this->method,
            'copies' => $this->copies,
            'note' => $this->note,
            'printed_by' => $this->whenLoaded('printer', fn () => $this->printer ? ['id' => $this->printer->public_id, 'name' => $this->printer->name] : null),
            'printed_at' => $this->printed_at,
        ];
    }
}
