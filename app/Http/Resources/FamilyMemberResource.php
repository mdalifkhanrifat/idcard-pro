<?php

namespace App\Http\Resources;

use App\Models\FamilyMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FamilyMember
 */
class FamilyMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'relation' => $this->relation,
            'name' => $this->name,
            'name_local' => $this->name_local,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'phone' => $this->phone,
            'blood_group' => $this->whenLoaded('bloodGroup', fn () => $this->bloodGroup ? [
                'id' => $this->bloodGroup->public_id,
                'name' => $this->bloodGroup->name,
            ] : null),
            'national_id' => $this->national_id,
            'passport_no' => $this->passport_no,
            'identification_mark' => $this->identification_mark,
            'card_no' => $this->card_no,
            'sort_order' => $this->sort_order,
            'updated_at' => $this->updated_at,
        ];
    }
}
