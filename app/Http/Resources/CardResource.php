<?php

namespace App\Http\Resources;

use App\Models\Card;
use App\Services\Cardholders\CardholderFileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Card
 */
class CardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'rf_card_no' => $this->rf_card_no,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'is_expired' => $this->isExpired(),
            'issue_date' => $this->issue_date->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'status_changed_at' => $this->status_changed_at,
            'status_note' => $this->status_note,
            'template_version' => $this->template_version,
            'print_count' => $this->print_count,
            'last_printed_at' => $this->last_printed_at,
            'template' => $this->whenLoaded('template', fn () => ['id' => $this->template->public_id, 'name' => $this->template->name, 'code' => $this->template->code]),
            'family_member' => $this->whenLoaded('familyMember', fn () => $this->familyMember ? [
                'id' => $this->familyMember->public_id, 'name' => $this->familyMember->name, 'relation' => $this->familyMember->relation->value,
            ] : null),
            'cardholder' => $this->whenLoaded('cardholder', fn () => [
                'id' => $this->cardholder->public_id, 'full_name' => $this->cardholder->full_name, 'employee_no' => $this->cardholder->employee_no,
                'department' => $this->cardholder->relationLoaded('department') ? $this->cardholder->department?->name : null,
            ]),
            'issued_by' => $this->whenLoaded('issuer', fn () => $this->issuer?->name),
            'replaced_by' => $this->whenLoaded('replacedBy', fn () => $this->replacedBy ? ['id' => $this->replacedBy->public_id, 'number' => $this->replacedBy->number] : null),
            'document' => $this->whenLoaded('document', fn () => $this->document ? [
                'id' => $this->document->public_id, 'name' => $this->document->original_name, 'url' => app(CardholderFileService::class)->temporaryUrl($this->document),
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
