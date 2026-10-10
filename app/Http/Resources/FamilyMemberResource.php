<?php

namespace App\Http\Resources;

use App\Models\CardholderFile;
use App\Models\FamilyMember;
use App\Services\Cardholders\CardholderFileService;
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
            'biometric_status' => $this->when((bool) $request->user()?->can('biometrics.view'), fn () => $this->biometric_status),
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
            'photo' => $this->whenLoaded('photo', fn () => $this->fileSummary($this->photo)),
            'signature' => $this->whenLoaded('signature', fn () => $this->fileSummary($this->signature)),
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return array{id: string, url: string, width: int|null, height: int|null}|null
     */
    private function fileSummary(?CardholderFile $file): ?array
    {
        return $file ? [
            'id' => $file->public_id,
            'url' => app(CardholderFileService::class)->temporaryUrl($file),
            'width' => $file->width,
            'height' => $file->height,
        ] : null;
    }
}
