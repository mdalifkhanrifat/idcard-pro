<?php

namespace App\Http\Resources;

use App\Models\Cardholder;
use App\Models\CardPrint;
use App\Models\MasterDataItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * One row of the card print history report: the printing, its card and the card's holder.
 *
 * @mixin CardPrint
 */
class CardPrintReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $card = $this->card;
        $holder = $card->cardholder;

        return [
            'id' => $this->public_id,
            'batch' => $this->batch,
            'method' => $this->method,
            'copies' => $this->copies,
            'note' => $this->note,
            'printed_by' => $this->printer ? ['id' => $this->printer->public_id, 'name' => $this->printer->name] : null,
            'printed_at' => $this->printed_at,
            'card' => [
                'id' => $card->public_id,
                'number' => $card->number,
                'status' => $card->status->value,
                'reason' => $card->reason,
                'issue_date' => $card->issue_date->toDateString(),
                'expiry_date' => $card->expiry_date?->toDateString(),
                'template' => ['id' => $card->template->public_id, 'name' => $card->template->name, 'code' => $card->template->code],
            ],
            'family_member' => $card->familyMember ? [
                'id' => $card->familyMember->public_id, 'name' => $card->familyMember->name, 'relation' => $card->familyMember->relation->value,
            ] : null,
            'cardholder' => [
                'id' => $holder->public_id,
                'full_name' => $holder->full_name,
                'full_name_local' => $holder->full_name_local,
                'employee_no' => $holder->employee_no,
                // Every master data reference (rank, branch, department...), null when not set: a stable shape for any client
                'lookups' => collect(Cardholder::LOOKUPS)->mapWithKeys(function (string $type) use ($holder) {
                    /** @var MasterDataItem|null $item */
                    $item = $holder->getRelationValue(Str::camel($type));

                    return [$type => $item ? ['id' => $item->public_id, 'name' => $item->name, 'code' => $item->code] : null];
                })->all(),
            ],
        ];
    }
}
