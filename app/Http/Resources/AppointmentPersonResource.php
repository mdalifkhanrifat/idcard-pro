<?php

namespace App\Http\Resources;

use App\Models\Cardholder;
use App\Models\FamilyMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A person to book an appointment for: only who they are and their family members (5.5b).
 *
 * @mixin Cardholder
 */
class AppointmentPersonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->full_name,
            'name_local' => $this->full_name_local,
            'employee_no' => $this->employee_no,
            'status' => $this->status->value,
            'family_members' => $this->familyMembers->map(fn (FamilyMember $member) => ['id' => $member->public_id, 'name' => $member->name, 'relation' => $member->relation->value])->values(),
        ];
    }
}
